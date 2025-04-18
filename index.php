<?php
// 模型常量定义
define('MODEL_CHAT', 'grok-3-beta');
define('MODEL_VISION', 'grok-2-vision-latest');
define('MODEL_IMAGE', 'grok-2-image-latest');
define('MODEL_REASONING', 'grok-3-mini-beta');

// 检查是否有前端保存的API密钥和账户ID
if (isset($_POST['save_credentials']) && isset($_POST['api_key']) && isset($_POST['account_id'])) {
    if ($isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => '凭据已保存']);
        exit;
    }
}

/**
 * 确定要使用的模型
 * @param string $requestedModel 请求的模型
 * @param array|null $image 上传的图片
 * @return string 确定的模型
 */
function determineModel($requestedModel, $image = null) {
    // 如果有图片上传，强制使用视觉模型
    if ($image && $image["error"] === 0) {
        return MODEL_VISION;
    }
    
    // 处理模型选择
    if ($requestedModel === MODEL_IMAGE) {
        return MODEL_IMAGE;
    } else if ($requestedModel === MODEL_VISION) {
        return MODEL_VISION;
    } else if ($requestedModel === MODEL_REASONING) {
        return MODEL_REASONING;
    } else {
        return MODEL_CHAT;
    }
  }
/**
 * 构建消息内容
 * @param string $message 文本消息
 * @param array|null $image 上传的图片
 * @return array 消息内容数组
 */
function buildMessageContent($message, $image = null) {
    $content = [];
    
    // 添加文本内容
    if ($message) {
        $content[] = [
            "type" => "text",
            "text" => $message,
        ];
    }
    
    // 添加图片内容
    if ($image && $image["error"] === 0) {
        $imageData = base64_encode(file_get_contents($image["tmp_name"]));
        $content[] = [
            "type" => "image_url",
            "image_url" => [
                "url" => "data:image/jpeg;base64," . $imageData,
                "detail" => "high",
            ],
        ];
    }
    
    return $content;
}

require_once 'Parsedown.php';

// 配置环境参数
if ($env = @parse_ini_file(".env")) {
    $_ENV["api-key"] = $env["api-key"];
    $_ENV["cf-account-id"] = $env["cf-account-id"];
} elseif (getenv("api-key")) {
    $_ENV["api-key"] = getenv("api-key");
    $_ENV["cf-account-id"] = getenv("cf-account-id");
} else {
    $_ENV["api-key"] = "YOUR_API_KEY"; // 确保替换为你的真实API密钥
    $_ENV["cf-account-id"] = "8c9f126e8236df7c3ecfb44264c18351"; // 确保替换为你的CF账户ID
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    header("Cache-Control: public, max-age=120");
}

// 检查是否为AJAX请求
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// 处理清除历史的 AJAX 请求
if (isset($_GET['clear_history']) && $isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => '历史已清除'
    ]);
    exit;
}

// 处理保存响应的请求
if (isset($_POST['saveResponse']) && $_POST['saveResponse'] === 'true') {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true
        ]);
        exit;
    }
}

// 流式响应处理器
if (isset($_GET['stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // 禁用nginx缓冲
    ob_implicit_flush(true); // 强制输出缓冲区刷新
    if (ob_get_level() > 0) ob_end_flush();
    
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1'); // 禁用Apache的gzip压缩
    }
    
    $msg = trim($_POST["message"] ?? "");
    $requestedModel = $_POST["actual_model"] ?? $_POST["model"] ?? MODEL_CHAT;
    $image = $_FILES["image"] ?? null;
    
    // 使用辅助函数确定模型
    $model = determineModel($requestedModel, $image);
    
    // 使用辅助函数构建消息内容
    $content = buildMessageContent($msg, $image);
    
    // 构建消息数组，使用客户端提供的消息历史
    $messages = [];
    $messagesJson = $_POST["messages"] ?? "[]";
    $messagesData = json_decode($messagesJson, true);
    
    if(is_array($messagesData)) {
        $messages = $messagesData;
    }
    
    // 添加用户消息
    $messages[] = ["role" => "user", "content" => $content];
    
    // 获取客户端提供的API密钥和账户ID
    $apiKey = $_POST["api_key"] ?? $_ENV["api-key"];
    $accountId = $_POST["account_id"] ?? $_ENV["cf-account-id"];
    
    // 用流式处理聊天请求
    $ch = curl_init("https://gateway.ai.cloudflare.com/v1/".$accountId."/ai/grok/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "model" => $model,
            "messages" => $messages,
            "stream" => true,
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $apiKey,
        ],
        CURLOPT_WRITEFUNCTION => function($curl, $data) {
            echo $data;
            flush();
            return strlen($data);
        }
    ]);
    
    $response = curl_exec($ch);
    
    // 检查是否有错误
    if (curl_errno($ch)) {
        echo "data: " . json_encode(["error" => curl_error($ch)]) . "\n\n";
        flush();
    }
    
    curl_close($ch);
    exit;
}

// 图像生成处理器
if (isset($_GET['generate_image'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    
    $msg = trim($_POST["message"] ?? "");
    
    // 图像生成始终使用图像模型
    $model = MODEL_IMAGE;
    
    if ($msg) {
        // 构建消息，使用客户端提供的消息历史
        $messages = [];
        $messagesJson = $_POST["messages"] ?? "[]";
        $messagesData = json_decode($messagesJson, true);
        
        if(is_array($messagesData)) {
            $messages = $messagesData;
        }
        
        // 添加用户消息
        $messages[] = [
            "role" => "user", 
            "content" => [
                [
                    "type" => "text",
                    "text" => $msg,
                ]
            ]
        ];
        
        // 获取客户端提供的API密钥和账户ID
        $apiKey = $_POST["api_key"] ?? $_ENV["api-key"];
        $accountId = $_POST["account_id"] ?? $_ENV["cf-account-id"];
        
        $ch = curl_init("https://gateway.ai.cloudflare.com/v1/".$accountId."/ai/grok/v1/images/generations");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                "model" => MODEL_IMAGE,
                "prompt" => $msg,
                "n" => 1,
                "response_format" => "b64_json"
            ]),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $apiKey,
            ],
        ]);
        
        $response = curl_exec($ch);
        $res = json_decode($response, true);
        
        if (!empty($res["data"])) {
            $imageContent = [];
            foreach ($res["data"] as $image) {
                $imageContent[] = [
                    "type" => "image_url",
                    "image_url" => [
                        "url" => "data:image/png;base64," . ($image["b64_json"] ?? ''),
                    ],
                ];
            }
            $responseMessage = [
                "role" => "assistant",
                "content" => $imageContent,
            ];
            
            $messages[] = $responseMessage;
            
            echo json_encode([
                'success' => true,
                'images' => $imageContent,
                'messages' => $messages
            ]);
        } else {
            $responseMessage = [
                "role" => "assistant",
                "content" => [
                    [
                        "type" => "text",
                        "text" => "图像生成失败。请稍后再试。"
                    ]
                ]
            ];
            
            $messages[] = $responseMessage;
            
            echo json_encode([
                'success' => false,
                'error' => '图像生成失败。请稍后再试。',
                'messages' => $messages
            ]);
        }
        
        curl_close($ch);
    } else {
        echo json_encode([
            'success' => false,
            'error' => '请提供生成图像的提示文本。'
        ]);
    }
    
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST['saveResponse'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Grok Chat</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" href="https://docs.x.ai/favicon.ico" type="image/x-icon">
  <link href="https://fastly.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet"/>
  <link href="parsedown.css" rel="stylesheet"/>
  <style>
    .typing-indicator {
      display: flex;
      align-items: center;
      margin: 8px 0;
    }
    .typing-indicator span {
      height: 8px;
      width: 8px;
      margin: 0 1px;
      background-color: #999;
      border-radius: 50%;
      display: inline-block;
      animation: bounce 1.5s infinite ease-in-out;
      opacity: 0.6;
    }
    .typing-indicator span:nth-child(2) {
      animation-delay: 0.2s;
    }
    .typing-indicator span:nth-child(3) {
      animation-delay: 0.4s;
    }
    @keyframes bounce {
      0%, 60%, 100% { transform: translateY(0); opacity: 0.6; }
      30% { transform: translateY(-6px); opacity: 1; }
    }
    
    /* 气泡样式 */
    .assistant-bubble {
      background-color: #F3F4F6;
      border-radius: 0 1.5rem 1.5rem 1.5rem;
      padding: 0.75rem;
      box-shadow: 0 1px 2px rgba(0,0,0,0.05);
      animation: fadeIn 0.3s ease-out;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    /* 打字机效果 */
    .typewriter {
      overflow: hidden;
      white-space: pre-wrap;
      word-wrap: break-word;
      position: relative;
    }
    
    /* 闪烁光标动画 */
    .cursor-blink::after {
      content: '|';
      display: inline-block;
      animation: blink 0.7s infinite;
    }
    
    @keyframes blink {
      0%, 100% { opacity: 1; }
      50% { opacity: 0; }
    }
    
    /* Markdown代码高亮 */
    pre {
      background-color: #2d2d2d;
      color: #f8f8f2;
      border-radius: 0.5rem;
      padding: 1rem;
      overflow-x: auto;
    }
    
    code {
      font-family: monospace;
      background-color: rgba(0,0,0,0.05);
      padding: 0.1rem 0.3rem;
      border-radius: 0.25rem;
    }
    
    pre code {
      background-color: transparent;
      padding: 0;
    }
    
    /* 防止滚动时的闪烁 */
    #chat-container {
      scroll-behavior: smooth;
      will-change: scroll-position;
    }
    
    /* 减少消息间距，更紧凑 */
    #messages-container {
      gap: 4px;
    }
    
    /* 模型选择容器样式 */
    .model-select-container {
      transition: all 0.3s ease;
    }
    
    /* 按钮禁用状态样式 */
    button:disabled,
    button.disabled {
      opacity: 0.5;
      cursor: not-allowed;
      pointer-events: none;
    }
    
    /* 绘图按钮激活状态 */
    #draw-image-btn.active {
      background-color: #dbeafe;
      color: #3b82f6;
      box-shadow: 0 0 0 1px #3b82f6;
    }

    /* 推理按钮激活状态 */
    #reasoning-btn.active {
      background-color: #dbeafe;
      color: #3b82f6;
      box-shadow: 0 0 0 1px #3b82f6;
    }

    /* 思考过程容器 */
    .think-container {
      background-color: #f8f9fa;
      border-left: 3px solid #4b5563;
      margin: 0.5rem 0;
      padding: 0.5rem;
      border-radius: 0 0.25rem 0.25rem 0;
      overflow: hidden;
      max-height: 0;
      transition: max-height 0.3s ease-out;
    }

    .think-container.expanded {
      max-height: 1000px; /* 足够大的高度以显示内容 */
      transition: max-height 0.5s ease-in;
    }

    .think-toggle {
      display: block;
      user-select: none;
      cursor: pointer;
      color: #4b5563;
      font-size: 0.85rem;
      padding: 0.25rem 0;
    }

    .think-toggle:hover {
      text-decoration: underline;
    }

    .think-toggle::before {
      content: '▶ 展开思考过程';
      display: inline-block;
      transition: transform 0.3s;
    }

    .think-toggle.expanded::before {
      content: '▼ 收起思考过程';
    }

    /* 图片预览样式 */
    #image-preview {
      transition: all 0.3s ease;
    }
    
    #image-preview.has-image {
      animation: fadeIn 0.3s forwards;
    }
    
    #image-preview img {
      max-width: 120px;
      max-height: 50px;
      object-fit: contain;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      border-radius: 6px;
    }
    
    #message-input {
      transition: all 0.2s ease;
      word-break: break-word;
      overflow-wrap: break-word;
      max-height: 200px; /* 添加最大高度限制 */
      overflow-y: auto; /* 超过最大高度时允许滚动 */
      width: 100%;
      min-height: 24px;
      line-height: 1.5;
      padding-top: 0.5rem;
      padding-bottom: 0.5rem;
      box-sizing: border-box;
    }
    
    /* 模态框样式 */
    .modal {
      display: none;
      position: fixed;
      z-index: 50;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      overflow: auto;
      backdrop-filter: blur(4px);
    }
    
    .modal-content {
      background-color: #fff;
      margin: 10% auto;
      padding: 20px;
      border-radius: 12px;
      width: 90%;
      max-width: 500px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.1);
      transform: translateY(0);
      transition: transform 0.3s ease-out;
    }
    
    .modal.show {
      display: block;
    }
    
    .modal.show .modal-content {
      animation: slideIn 0.3s forwards;
    }
    
    @keyframes slideIn {
      from { transform: translateY(-50px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
  </style>
</head>
<body class="bg-[#f9f8f6]">
<main class="h-dvh flex flex-col">
  <header class="fixed w-full z-40 bg-gradient-to-b from-gray-100 to-transparent p-1">
    <div class="max-w-[50rem] mx-auto flex justify-between items-center py-1">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-lg bg-[#1C1C1C] flex items-center justify-center">
          <i class="ri-twitter-x-fill text-xl text-white"></i>
        </div>
        <span class="text-xl font-semibold">Grok</span>
      </div>
      <div class="flex items-center gap-3">
        <a href="#" id="clear-history-btn" title="清除历史" class="p-1 hover:bg-gray-200 rounded-full leading-none transition-colors"><i class="ri-edit-line text-xl leading-none"></i></a>
        <button title="分享" onclick="navigator.share({title:'Grok聊天',text:'查看我与Grok的聊天',url:window.location.href})" class="p-1 hover:bg-gray-200 rounded-full leading-none transition-colors">
          <i class="ri-share-2-line text-xl leading-none"></i>
        </button>
        <button id="api-key-btn" title="设置API密钥" class="p-1 hover:bg-gray-200 rounded-full leading-none cursor-pointer transition-colors">
          <i class="ri-key-2-line text-xl leading-none"></i>
        </button>
      </div>
    </div>
  </header>
  <div id="chat-container" class="flex-1 overflow-y-auto px-5 pt-16 pb-40">
    <div id="messages-container" class="max-w-[50rem] mx-auto flex flex-col gap-4 pb-4">
    </div>
  </div>
  <div class="fixed bottom-0 w-full max-w-[50rem] left-1/2 -translate-x-1/2 p-3">
    <form id="chat-form" method="POST" enctype="multipart/form-data" class="flex flex-col relative bg-stone-50 p-2 rounded-3xl ring-1 ring-gray-200 hover:ring-gray-300 hover:shadow hover:bg-white focus-within:ring-gray-300 duration-300">
      <div class="relative flex-grow">
        <textarea id="message-input" name="message" class="w-full px-3 py-2 bg-transparent focus:outline-none resize-none overflow-auto" placeholder="Grok能帮您什么?" oninput="updateReplicatedValue(this)" rows="1"></textarea>
        <div id="image-preview" class="hidden absolute right-10 top-1/2 -translate-y-1/2 mr-2">
          <div class="relative inline-block">
            <img src="" alt="预览" class="rounded border border-gray-300"/>
            <button type="button" onclick="clearImage()" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center shadow-sm text-xs">×</button>
          </div>
        </div>
      </div>
      <div class="flex items-center justify-between mt-1">
        <div class="flex items-center gap-3 px-3">
          <button type="button" id="reasoning-btn" class="p-1 hover:bg-gray-200 rounded-full leading-none cursor-pointer transition-colors" title="推理">
            <i class="ri-question-line text-xl leading-none"></i>
          </button>
          <button type="button" id="draw-image-btn" class="p-1 hover:bg-gray-200 rounded-full leading-none cursor-pointer transition-colors" title="绘图">
            <i class="ri-image-add-line text-xl leading-none"></i>
          </button>
          <label class="p-1 hover:bg-gray-200 rounded-full leading-none cursor-pointer transition-colors" title="上传图片">
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp" class="hidden" onchange="showImagePreview(this)"/>
            <i class="ri-camera-line text-xl leading-none"></i>
          </label>
        </div>
        <div>
          <button id="submit-button" type="submit" disabled class="rounded-full bg-black hover:bg-gray-600 text-white p-2 leading-none disabled:bg-gray-300 duration-300"><i class="ri-arrow-up-line text-xl"></i></button>
        </div>
      </div>
      <input type="hidden" name="actual_model" id="actual-model-input" value="">
      <input type="hidden" id="model-select" name="model" value="grok-3-beta">
      <input type="hidden" name="messages" id="messages-input" value="[]">
    </form>
  </div>
</main>

<!-- 创建加载动画模板 -->
<template id="loading-template">
  <div class="flex parsedown justify-start">
    <div class="max-w-[80%] assistant-bubble">
      <div class="typing-indicator">
        <span></span>
        <span></span>
        <span></span>
      </div>
    </div>
  </div>
</template>

<!-- 创建用户消息模板 -->
<template id="user-message-template">
  <div class="flex justify-end">
    <div class="max-w-[80%] p-3 bg-blue-500 text-white rounded-l-3xl rounded-t-3xl">
    </div>
  </div>
</template>

<!-- 创建助手消息模板 -->
<template id="assistant-message-template">
  <div class="flex parsedown justify-start">
    <div class="max-w-[80%] assistant-bubble">
      <div class="typewriter"></div>
    </div>
  </div>
</template>

<!-- API密钥设置模态框 -->
<div id="api-key-modal" class="modal">
  <div class="modal-content">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-semibold">设置API凭据</h3>
      <button id="close-modal" class="p-1 hover:bg-gray-100 rounded-full">
        <i class="ri-close-line text-xl"></i>
      </button>
    </div>
    <form id="api-credentials-form" class="space-y-4">
      <div>
        <label for="api-key" class="block text-sm font-medium text-gray-700 mb-1">
          API密钥 <span class="text-red-500">*</span>
        </label>
        <input type="password" id="api-key" name="api_key" placeholder="请输入您的Grok API密钥" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" required>
      </div>
      <div>
        <label for="account-id" class="block text-sm font-medium text-gray-700 mb-1">
          Cloudflare账户ID <span class="text-red-500">*</span>
        </label>
        <input type="text" id="account-id" name="account_id" placeholder="请输入您的Cloudflare账户ID" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" required>
      </div>
      <div class="text-sm text-gray-500 mb-4">
        <p>您的凭据会保存在浏览器本地存储中，不会发送到服务器。</p>
        <p class="mt-1">您需要前往<a href="https://dash.cloudflare.com/?to=/:account/ai/ai-gateway" target="_blank" class="text-blue-500 hover:underline">Cloudflare控制面板</a>中创建AI Gateway，<a href="https://69.197.134.230/archives/grok-dui-hua-mian-ban-jiao-cheng" target="_blank" class="text-blue-500 hover:underline">查看教程</a>。</p>
      </div>
      <div class="flex justify-end">
        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-md transition-colors">保存</button>
      </div>
    </form>
  </div>
</div>

<!-- 清除历史确认模态框 -->
<div id="clear-history-modal" class="modal">
  <div class="modal-content">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-semibold">清除聊天历史</h3>
      <button id="close-history-modal" class="p-1 hover:bg-gray-100 rounded-full">
        <i class="ri-close-line text-xl"></i>
      </button>
    </div>
    <div class="space-y-4">
      <div class="text-gray-700">
        <p>确定要清除所有聊天历史吗？</p>
        <p class="text-sm text-gray-500 mt-2">此操作无法撤销，所有对话记录将被删除。</p>
      </div>
      <div class="flex justify-end gap-3">
        <button id="cancel-clear" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-100 transition-colors">取消</button>
        <button id="confirm-clear" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-md transition-colors">确认清除</button>
      </div>
    </div>
  </div>
</div>

<script>
// localStorage存储键名定义
const STORAGE_KEYS = {
  API_KEY: 'grok_api_key',
  ACCOUNT_ID: 'grok_account_id',
  MESSAGES: 'grok_messages',
  MODEL: 'grok_model'
};

// 添加全局变量，表示是否有API凭据
let hasApiCredentials = false;

// 模型管理器对象
const ModelManager = {
  // 模型类型常量
  MODELS: {
    CHAT: 'grok-3-beta',
    VISION: 'grok-2-vision-latest',
    IMAGE: 'grok-2-image-latest',
    REASONING: 'grok-3-mini-beta'
  },
  
  // 当前模型状态
  currentModel: 'grok-3-beta',
  previousModel: 'grok-3-beta',
  
  // 设置当前模型
  setModel: function(model) {
    this.previousModel = this.currentModel;
    this.currentModel = model;
    
    // 保存到localStorage
    localStorage.setItem(STORAGE_KEYS.MODEL, model);
    
    // 更新UI
    this.updateUI();
    
    return this.currentModel;
  },

  // 设置推理模式
  setReasoningMode: function() {
  // 检查是否已上传图片
  if (document.querySelector('input[type=file]').files[0]) {
    // 有图片上传时不能进入推理模式
    return this.currentModel;
  }
  
  this.previousModel = this.currentModel;
  this.currentModel = this.MODELS.REASONING;
  
  // 保存到localStorage
  localStorage.setItem(STORAGE_KEYS.MODEL, this.currentModel);
  
  // 更新隐藏输入
  document.getElementById('model-select').value = this.MODELS.REASONING;
  
  // 更新UI
  this.updateUI();
  
  // 聚焦消息输入框并更新提示
  const messageInput = document.getElementById('message-input');
  messageInput.focus();
  messageInput.placeholder = "请提出需要深度思考的问题...";
  
  // 推理按钮激活状态
  document.getElementById('reasoning-btn').classList.add('active');
  
  // 绘图按钮取消激活状态
  document.getElementById('draw-image-btn').classList.remove('active');
  
  return this.currentModel;
},
  
  // 设置绘图模式
  setDrawingMode: function() {
    // 检查是否已上传图片
    if (document.querySelector('input[type=file]').files[0]) {
      // 有图片上传时不能进入绘图模式
      return this.currentModel;
    }
    
    this.previousModel = this.currentModel;
    this.currentModel = this.MODELS.IMAGE;
    
    // 保存到localStorage
    localStorage.setItem(STORAGE_KEYS.MODEL, this.currentModel);
    
    // 更新隐藏输入
    document.getElementById('model-select').value = this.MODELS.IMAGE;
    
    // 更新UI
    this.updateUI();
    
    // 聚焦消息输入框并更新提示
    const messageInput = document.getElementById('message-input');
    messageInput.focus();
    messageInput.placeholder = this.getPlaceholder(this.MODELS.IMAGE);
    
    // 绘图按钮激活状态
    document.getElementById('draw-image-btn').classList.add('active');

  // 移除推理按钮激活状态
  document.getElementById('reasoning-btn').classList.remove('active');
    
    return this.currentModel;
  },
  
  // 切换回聊天模式
  setChatMode: function() {
    // 仅当当前是图像模式或推理模式时切换回聊天模式
    if (this.currentModel === this.MODELS.IMAGE || this.currentModel === this.MODELS.REASONING) {
      this.currentModel = this.MODELS.CHAT;
      
      // 保存到localStorage
      localStorage.setItem(STORAGE_KEYS.MODEL, this.currentModel);
      
      // 更新隐藏输入
      document.getElementById('model-select').value = this.MODELS.CHAT;
      
      // 更新UI
      this.updateUI();
      
      // 移除绘图按钮激活状态
      document.getElementById('draw-image-btn').classList.remove('active');

      // 移除推理按钮激活状态
      document.getElementById('reasoning-btn').classList.remove('active');
    }
    
    return this.currentModel;
  },
  
  // 保存当前模型状态
  saveModelState: function() {
    this.previousModel = this.currentModel;
  },
  
  // 恢复模型状态
  restoreModelState: function() {
    const temp = this.currentModel;
    this.currentModel = this.previousModel;
    this.previousModel = temp;
    
    // 保存到localStorage
    localStorage.setItem(STORAGE_KEYS.MODEL, this.currentModel);
    
    // 更新UI
    this.updateUI();
    
    return this.currentModel;
  },
  
  // 设置视觉模型
  setVisionModel: function() {
    // 保存当前模型以便后续恢复
    this.saveModelState();
    
    // 设置为视觉模型
    document.getElementById('actual-model-input').value = this.MODELS.VISION;
    return this.setModel(this.MODELS.VISION);
  },
  
  // 清除视觉模型
  clearVisionModel: function() {
    // 清除实际模型值
    document.getElementById('actual-model-input').value = "";
    
    // 恢复之前的模型
    return this.restoreModelState();
  },
  
  // 获取UI提示文本
  getPlaceholder: function(model) {
    if (model === this.MODELS.IMAGE) {
      return "请描述您想生成的图像...";
    } else if (model === this.MODELS.VISION) {
      return "您可以提问或上传图片...";
    } else if (model === this.MODELS.REASONING) {
      return "请提出需要深度思考的问题...";
    } else {
      return "Grok能帮您什么?";
    }
  },
  
  // 更新UI状态
  updateUI: function() {
    // 更新隐藏的模型输入值
    const modelSelect = document.getElementById('model-select');
    if (modelSelect && this.currentModel !== this.MODELS.VISION) {
      modelSelect.value = this.currentModel;
    }
    
    // 更新输入框提示
    const messageInput = document.getElementById('message-input');
    if (messageInput) {
      messageInput.placeholder = this.getPlaceholder(this.currentModel);
    }
    
    // 绘图模式激活状态
    const drawButton = document.getElementById('draw-image-btn');
    if (drawButton) {
      if (this.currentModel === this.MODELS.IMAGE) {
        drawButton.classList.add('active');
      } else {
        drawButton.classList.remove('active');
      }
    }

    // 推理模式激活状态
    const reasoningButton = document.getElementById('reasoning-btn');
    if (reasoningButton) {
      if (this.currentModel === this.MODELS.REASONING) {
        reasoningButton.classList.add('active');
      } else {
        reasoningButton.classList.remove('active');
      }
    }
  },
  
  // 从localStorage加载模型状态
  loadFromStorage: function() {
    const storedModel = localStorage.getItem(STORAGE_KEYS.MODEL);
    if (storedModel) {
      this.currentModel = storedModel;
      this.updateUI();
    }
  }
};

// 聊天消息管理器
const MessageManager = {
  // 消息数组
  messages: [],
  
  // 添加消息
  addMessage: function(role, content) {
    const message = { role, content };
    this.messages.push(message);
    this.saveToStorage();
    
    // 更新隐藏输入字段
    document.getElementById('messages-input').value = JSON.stringify(this.messages);
    
    return message;
  },
  
  // 保存到localStorage
  saveToStorage: function() {
    localStorage.setItem(STORAGE_KEYS.MESSAGES, JSON.stringify(this.messages));
  },
  
  // 从localStorage加载
  loadFromStorage: function() {
    const storedMessages = localStorage.getItem(STORAGE_KEYS.MESSAGES);
    if (storedMessages) {
      try {
        this.messages = JSON.parse(storedMessages);
        
        // 更新隐藏输入字段
        document.getElementById('messages-input').value = storedMessages;
        
        // 渲染所有消息
        this.renderAllMessages();
      } catch (error) {
        console.error('加载聊天记录时出错:', error);
        this.messages = [];
      }
    }
  },
  
  // 清除所有消息
  clearMessages: function() {
    this.messages = [];
    this.saveToStorage();
    
    // 更新隐藏输入字段
    document.getElementById('messages-input').value = JSON.stringify(this.messages);
    
    // 清空消息容器
    document.getElementById('messages-container').innerHTML = '';
  },
  
  // 渲染所有消息
  renderAllMessages: function() {
    const messagesContainer = document.getElementById('messages-container');
    messagesContainer.innerHTML = ''; // 清空容器
    
    // 使用Parsedown实例来渲染Markdown
    const parsedown = {
      text: markdownParser
    };
    
    this.messages.forEach(message => {
      const isUser = message.role === "user";
      
      // 创建消息元素
      const messageDiv = document.createElement('div');
      messageDiv.className = `flex ${isUser ? "justify-end" : "parsedown justify-start"}`;
      
      const contentDiv = document.createElement('div');
      contentDiv.className = isUser 
        ? "max-w-[80%] bg-blue-500 text-white rounded-l-3xl rounded-t-3xl p-3"
        : "max-w-[80%] assistant-bubble";
      
      // 处理内容
      if (Array.isArray(message.content)) {
        message.content.forEach(content => {
          if (content.type === "image_url") {
            const img = document.createElement('img');
            img.src = content.image_url.url;
            img.className = "max-w-full rounded-lg mb-2";
            contentDiv.appendChild(img);
          } else if (content.type === "text") {
            const textDiv = document.createElement('div');
            textDiv.innerHTML = parsedown.text(content.text || "");
            contentDiv.appendChild(textDiv);
          } else {
            const textDiv = document.createElement('div');
            textDiv.innerHTML = parsedown.text(content || "");
            contentDiv.appendChild(textDiv);
          }
        });
      } else {
        contentDiv.innerHTML = parsedown.text(message.content || "错误：内容缺失");
      }
      
      messageDiv.appendChild(contentDiv);
      messagesContainer.appendChild(messageDiv);
    });
    
    // 滚动到底部
    scrollToBottom(true);
  }
};

// 凭据管理器
const CredentialsManager = {
  // 保存凭据到localStorage
  saveCredentials: function(apiKey, accountId) {
    localStorage.setItem(STORAGE_KEYS.API_KEY, apiKey);
    localStorage.setItem(STORAGE_KEYS.ACCOUNT_ID, accountId);
    hasApiCredentials = true;
  },
  
  // 从localStorage获取凭据
  getCredentials: function() {
    return {
      apiKey: localStorage.getItem(STORAGE_KEYS.API_KEY),
      accountId: localStorage.getItem(STORAGE_KEYS.ACCOUNT_ID)
    };
  },
  
  // 检查是否有凭据
  hasCredentials: function() {
    const { apiKey, accountId } = this.getCredentials();
    return !!(apiKey && accountId);
  },
  
  // 清除凭据
  clearCredentials: function() {
    localStorage.removeItem(STORAGE_KEYS.API_KEY);
    localStorage.removeItem(STORAGE_KEYS.ACCOUNT_ID);
    hasApiCredentials = false;
  }
};

const messageInput = document.getElementById('message-input'),
      submitButton = document.getElementById('submit-button'),
      chatContainer = document.getElementById('chat-container'),
      messagesContainer = document.getElementById('messages-container'),
      chatForm = document.getElementById('chat-form'),
      loadingTemplate = document.getElementById('loading-template'),
      userMessageTemplate = document.getElementById('user-message-template'),
      assistantMessageTemplate = document.getElementById('assistant-message-template'),
      modelSelect = document.getElementById('model-select');

// 延迟滚动处理器
let scrollDebounceTimer;

// 展示图片预览
const showImagePreview = (input) => {
  if (!input.files[0]) return;
  
  // 如果存在文件，则自动退出绘图模式
  if (ModelManager.currentModel === ModelManager.MODELS.IMAGE) {
    ModelManager.setChatMode();
  }
  
  // 禁用绘图按钮
  const drawButton = document.getElementById('draw-image-btn');
  drawButton.classList.add('opacity-50', 'cursor-not-allowed');
  drawButton.disabled = true;

  // 禁用推理按钮
  const reasoningButton = document.getElementById('reasoning-btn');
  reasoningButton.classList.add('opacity-50', 'cursor-not-allowed');
  reasoningButton.disabled = true;
  
  let reader = new FileReader();
  reader.onload = (e) => {
    document.querySelector('#image-preview img').src = e.target.result;
    document.getElementById('image-preview').classList.add('has-image');
    document.getElementById('image-preview').classList.remove('hidden');
    
    submitButton.disabled = false;
  };
  reader.readAsDataURL(input.files[0]);
};

// 清除图片
const clearImage = () => {
  document.querySelector('input[type=file]').value = '';
  document.getElementById('image-preview').classList.add('hidden');
  document.getElementById('image-preview').classList.remove('has-image');
  
  submitButton.disabled = !messageInput.value.trim();
  
  // 重新启用绘图按钮
  const drawButton = document.getElementById('draw-image-btn');
  drawButton.classList.remove('opacity-50', 'cursor-not-allowed');
  drawButton.disabled = false;

  // 重新启用推理按钮
  const reasoningButton = document.getElementById('reasoning-btn');
  reasoningButton.classList.remove('opacity-50', 'cursor-not-allowed');
  reasoningButton.disabled = false;
};

// 优化的滚动函数
const scrollToBottom = (immediate = false) => {
  if (immediate) {
    chatContainer.scrollTop = chatContainer.scrollHeight;
    return;
  }
  
  // 防止过多滚动操作
  clearTimeout(scrollDebounceTimer);
  scrollDebounceTimer = setTimeout(() => {
    // 使用requestAnimationFrame确保平滑滚动
    requestAnimationFrame(() => {
      const shouldScroll = chatContainer.scrollHeight - chatContainer.clientHeight <= 
                         chatContainer.scrollTop + 200;
      
      if (shouldScroll) {
        chatContainer.scrollTop = chatContainer.scrollHeight;
      }
    });
  }, 100);
};

// 添加用户消息到界面
const addUserMessage = (message, imageElement = null) => {
  const userMsgDiv = userMessageTemplate.content.cloneNode(true).firstElementChild;
  const contentDiv = userMsgDiv.querySelector('div');
  
  if (message && message.trim()) {
    contentDiv.textContent = message;
  }
  
  if (imageElement) {
    const img = document.createElement('img');
    img.src = imageElement.src;
    img.className = 'max-w-full rounded-lg mb-2';
    contentDiv.appendChild(img);
  }
  
  messagesContainer.appendChild(userMsgDiv);
  scrollToBottom(true);
  
  // 构建消息内容
  const content = [];
  
  if (message && message.trim()) {
    content.push({
      type: "text",
      text: message
    });
  }
  
  if (imageElement) {
    content.push({
      type: "image_url",
      image_url: {
        url: imageElement.src
      }
    });
  }
  
  // 添加到消息管理器
  MessageManager.addMessage("user", content);
};

// 高效的Markdown解析
const markdownParser = (() => {
  // 预编译正则表达式以提高性能
  const rules = [
    // 添加标题解析 (h1 到 h6)
    [/^# (.*?)$/gm, '<h1>$1</h1>'],
    [/^## (.*?)$/gm, '<h2>$1</h2>'],
    [/^### (.*?)$/gm, '<h3>$1</h3>'],
    [/^#### (.*?)$/gm, '<h4>$1</h4>'],
    [/^##### (.*?)$/gm, '<h5>$1</h5>'],
    [/^###### (.*?)$/gm, '<h6>$1</h6>'],
    [/\*\*(.*?)\*\*/g, '<strong>$1</strong>'],
    [/\*(.*?)\*/g, '<em>$1</em>'],
    [/`([^`\n]+)`/g, '<code>$1</code>'],
    [/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>'],
    [/\n/g, '<br>'],
    [/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>']
  ];
  
  // 缓存已解析的内容
  const cache = new Map();
  
  return (text) => {
    if (cache.has(text)) return cache.get(text);
    
    let result = text;
    // 应用所有规则
    for (const [pattern, replacement] of rules) {
      result = result.replace(pattern, replacement);
    }
    
    // 只缓存一定长度的文本，避免内存泄漏
    if (text.length < 1000) {
      cache.set(text, result);
    }
    
    return result;
  };
})();

// 创建流式响应容器
const createStreamingResponseContainer = () => {
  const assistantMsgDiv = assistantMessageTemplate.content.cloneNode(true).firstElementChild;
  messagesContainer.appendChild(assistantMsgDiv);
  scrollToBottom(true);
  return assistantMsgDiv.querySelector('.typewriter');
};

// 添加加载指示器
const addLoadingIndicator = () => {
  const loadingDiv = loadingTemplate.content.cloneNode(true);
  messagesContainer.appendChild(loadingDiv);
  scrollToBottom(true);
  return messagesContainer.lastElementChild;
};

// 优化的流式处理
const processStream = (streamResponse, responseContainer) => {
  return new Promise((resolve) => {
    const reader = streamResponse.getReader();
    let responseText = '';
    let buffer = '';
    let contentBuffer = []; // 用于缓冲内容块
    let lastUpdateTime = Date.now();
    const updateInterval = 30; // 仅每30ms更新一次DOM
    let pendingUpdate = false;
    
    // 缓冲区更新函数
    const updateDisplay = () => {
      if (contentBuffer.length > 0) {
        responseText += contentBuffer.join('');
        contentBuffer = [];
        
        // 使用requestAnimationFrame确保渲染流畅
        requestAnimationFrame(() => {
          responseContainer.innerHTML = markdownParser(responseText);
          scrollToBottom();
          pendingUpdate = false;
        });
      } else {
        pendingUpdate = false;
      }
    };
    
    function processText() {
      reader.read().then(({ done, value }) => {
        if (done) {
          // 确保最后的内容更新
          if (contentBuffer.length > 0) {
            updateDisplay();
          }
          
          // 将完整的响应保存到消息管理器
          MessageManager.addMessage("assistant", [{
            type: "text",
            text: responseText
          }]);
          
          resolve();
          return;
        }
        
        // 解码流数据块
        const chunk = new TextDecoder().decode(value);
        buffer += chunk;
        
        // 按行分割缓冲区内容
        const lines = buffer.split('\n');
        
        // 最后一行可能不完整，保留在缓冲区
        buffer = lines.pop() || '';
        
        let hasContent = false;
        
        for (const line of lines) {
          // 只处理data:开头的行
          if (line.startsWith('data:')) {
            try {
              // 去掉data:前缀并清理多余的空格
              const jsonStr = line.substring(5).trim();
              
              // 检查是否为[DONE]消息
              if (jsonStr === '[DONE]') {
                continue;
              }
              
              const data = JSON.parse(jsonStr);
              if (data.choices && data.choices[0].delta && data.choices[0].delta.content) {
                const newContent = data.choices[0].delta.content;
                contentBuffer.push(newContent);
                hasContent = true;
              }
            } catch (e) {
              console.log('Stream parsing error:', e);
            }
          }
        }
        
        // 仅在有新内容且自上次更新已经过了足够时间时更新DOM
        if (hasContent && !pendingUpdate && Date.now() - lastUpdateTime > updateInterval) {
          lastUpdateTime = Date.now();
          pendingUpdate = true;
          updateDisplay();
        }
        
        processText();
      }).catch(error => {
        console.error('Stream reading error:', error);
        responseContainer.innerHTML += '<br><span class="text-red-500">读取流时出错。请尝试刷新页面。</span>';
   resolve();
      });
    }
    
    processText();
  });
};

// 添加绘图按钮点击事件处理
document.getElementById('draw-image-btn').addEventListener('click', () => {
  // 检查是否已上传图片
  if (document.querySelector('input[type=file]').files[0]) {
    // 已上传图片，不执行操作
    return;
  }
  
  // 如果当前不是图像模式，则切换到图像模式
  if (ModelManager.currentModel !== ModelManager.MODELS.IMAGE) {
    ModelManager.setDrawingMode();
  } else {
    // 如果当前已经是图像模式，则切换回聊天模式
    ModelManager.setChatMode();
  }
  
  // 聚焦输入框
  messageInput.focus();
});

// 添加推理按钮点击事件处理
document.getElementById('reasoning-btn').addEventListener('click', () => {
  // 检查是否已上传图片
  if (document.querySelector('input[type=file]').files[0]) {
    // 已上传图片，不执行操作
    return;
  }  
  // 如果当前不是推理模式，则切换到推理模式
  if (ModelManager.currentModel !== ModelManager.MODELS.REASONING) {
    ModelManager.setReasoningMode();
  } else {
    // 如果当前已经是推理模式，则切换回聊天模式
    ModelManager.setChatMode();
  }
  
  // 聚焦输入框
  messageInput.focus();
});

// 输入监听器
messageInput.addEventListener('input', () => {
  submitButton.disabled = !messageInput.value.trim() && !document.querySelector('input[type=file]').files[0];
  
  // 当用户开始输入时，如果是在图像生成模式下，判断输入内容性质
  if (ModelManager.currentModel === ModelManager.MODELS.IMAGE) {
    // 如果输入的内容看起来不像是图像描述（例如，是个问题），自动切换到聊天模式
    const text = messageInput.value.trim();
    if (text && (text.endsWith('?') || text.endsWith('？') || 
        /^(what|how|why|when|who|where|which|请问|为什么|怎么|如何|是什么)/i.test(text))) {
      // 切换回聊天模式
      ModelManager.setChatMode();
    }
  }
});

// 处理表单提交
chatForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  
  // 检查是否有API凭据，如果没有则显示API密钥设置模态框
  if (!hasApiCredentials) {
    apiKeyModal.classList.add('show');
    return;
  }
  
  const formData = new FormData(chatForm);
  const message = formData.get('message').trim();
  const imageInput = document.querySelector('input[type=file]');
  let imageElement = null;
  
  if (!message && (!imageInput.files || !imageInput.files[0])) {
    return; // 没有消息或图片，不提交
  }
  
  // 获取当前模型模式
  const isDrawMode = ModelManager.currentModel === ModelManager.MODELS.IMAGE;
  
  // 如果有图片上传，临时切换到视觉模型
  if (imageInput.files && imageInput.files[0]) {
    // 使用ModelManager设置视觉模型，仅当前请求有效
    ModelManager.setVisionModel();
    imageElement = document.querySelector('#image-preview img').cloneNode();
  }
  
  // 使用ModelManager获取当前模型
  const model = ModelManager.currentModel;
  formData.set('model', model);
  
  // 如果有隐藏的实际模型值（针对视觉模型），使用它
  const actualModelInput = document.getElementById('actual-model-input');
  if (actualModelInput.value) {
    formData.set('actual_model', actualModelInput.value);
  }
  
  // 获取凭据并添加到表单数据
  const credentials = CredentialsManager.getCredentials();
  if (credentials.apiKey) {
    formData.append('api_key', credentials.apiKey);
  }
  if (credentials.accountId) {
    formData.append('account_id', credentials.accountId);
  }
  
  // 添加消息历史到表单数据
  formData.set('messages', JSON.stringify(MessageManager.messages));
  
  // 显示用户消息
  addUserMessage(message, imageElement);
  
  // 清空输入
  messageInput.value = '';
  clearImage();
  submitButton.disabled = true;
  
  // 对于图像生成，使用异步方式
  if (model === ModelManager.MODELS.IMAGE) {
    // 显示加载指示器
    const loadingIndicator = addLoadingIndicator();
    
    try {
      // 异步请求图像生成
      const response = await fetch(`${window.location.href}?generate_image=1`, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      const result = await response.json();
      
      // 移除加载指示器
      loadingIndicator.remove();
      
      if (result.success) {
        // 创建助手消息容器
        const assistantMsgDiv = assistantMessageTemplate.content.cloneNode(true).firstElementChild;
        const typewriterDiv = assistantMsgDiv.querySelector('.typewriter');
        
        // 添加图像
        if (result.images && result.images.length > 0) {
          result.images.forEach(imgContent => {
            if (imgContent.type === 'image_url') {
              const img = document.createElement('img');
              img.src = imgContent.image_url.url;
              img.className = 'max-w-full rounded-lg mb-2';
              typewriterDiv.appendChild(img);
            }
          });
        }
        
        messagesContainer.appendChild(assistantMsgDiv);
        scrollToBottom(true);
        
        // 更新本地消息历史
        if (result.messages && Array.isArray(result.messages)) {
          MessageManager.messages = result.messages;
          MessageManager.saveToStorage();
        }
      } else {
        // 显示错误消息
        const responseContainer = createStreamingResponseContainer();
        responseContainer.innerHTML = result.error || '图像生成失败。请稍后再试。';
        
        // 添加错误消息到历史
        MessageManager.addMessage("assistant", [{
          type: "text",
          text: result.error || '图像生成失败。请稍后再试。'
        }]);
      }
      
    } catch (error) {
      console.error('图像生成错误:', error);
      loadingIndicator.remove();
      
      // 显示错误消息
      const responseContainer = createStreamingResponseContainer();
      responseContainer.innerHTML = '错误: 无法生成图像。请检查API密钥和账户ID，然后重试。';
      
      // 添加错误消息到历史
      MessageManager.addMessage("assistant", [{
        type: "text",
        text: '错误: 无法生成图像。请检查API密钥和账户ID，然后重试。'
      }]);
    } finally {
      // 如果有使用临时视觉模型，恢复之前的模型状态
      if (imageInput.files && imageInput.files[0]) {
        ModelManager.clearVisionModel();
      }
      
      // 确保UI状态一致
      ModelManager.updateUI();
      
      // 启用提交按钮，允许用户继续对话
      submitButton.disabled = !messageInput.value.trim() && !document.querySelector('input[type=file]').files[0];
    }
  } else {
    // 对于文本/视觉模型，使用流式传输
    
    // 创建流式响应容器
    const responseContainer = createStreamingResponseContainer();
    
    try {
      // 获取流
      const streamResponse = await fetch(`${window.location.href}?stream=1`, {
        method: 'POST',
        body: formData
      });
      
      if (!streamResponse.ok) {
        throw new Error(`HTTP error! status: ${streamResponse.status}`);
      }
      
      // 处理流
      await processStream(streamResponse.body, responseContainer);
      
    } catch (error) {
      console.error('流式错误:', error);
      responseContainer.innerHTML = '错误: 无法获取响应。请检查API密钥和账户ID，然后重试。';
      
      // 添加错误消息到历史
      MessageManager.addMessage("assistant", [{
        type: "text",
        text: '错误: 无法获取响应。请检查API密钥和账户ID，然后重试。'
      }]);
    } finally {
      // 如果有使用临时视觉模型，恢复之前的模型状态
      if (imageInput.files && imageInput.files[0]) {
        ModelManager.clearVisionModel();
      }
      
      // 确保UI状态一致
      ModelManager.updateUI();
      
      // 启用提交按钮，允许用户继续对话
      submitButton.disabled = !messageInput.value.trim() && !document.querySelector('input[type=file]').files[0];
    }
  }
});

// 回车键提交
messageInput.addEventListener('keydown', e => {
  if (e.shiftKey && e.key === 'Enter') return;
  if (e.key === 'Enter' && (messageInput.value.trim() || document.querySelector('input[type=file]').files[0])) {
    e.preventDefault();
    chatForm.dispatchEvent(new Event('submit'));
  }
});

// 页面加载完成，滚动到底部
window.addEventListener('load', () => {
  // 检查是否有API凭据
  hasApiCredentials = CredentialsManager.hasCredentials();
  
  // 从localStorage加载模型状态和消息历史
  ModelManager.loadFromStorage();
  MessageManager.loadFromStorage();
  
  // 滚动到底部
  scrollToBottom();
  
  // 初始化ModelManager状态并设置绘图按钮状态
  ModelManager.updateUI();
  
  // 初始化textarea的replicated value
  const textarea = document.getElementById('message-input');
  updateReplicatedValue(textarea);
  
  // 检查是否已经上传图片，如果有则禁用绘图和推理按钮
  if (document.querySelector('input[type=file]').files[0]) {
    const drawButton = document.getElementById('draw-image-btn');
    drawButton.classList.add('opacity-50', 'cursor-not-allowed');
    drawButton.disabled = true;
    const reasoningButton = document.getElementById('reasoning-btn');
    reasoningButton.classList.add('opacity-50', 'cursor-not-allowed');
    reasoningButton.disabled = true;
  }
  
  // 在页面加载时根据当前输入状态设置按钮状态
  submitButton.disabled = !messageInput.value.trim() && !document.querySelector('input[type=file]').files[0];
  
  // 检查是否有错误消息，优雅地向用户显示
  document.querySelectorAll('.assistant-bubble').forEach(bubble => {
    if (bubble.textContent.includes('获取响应失败') || 
        bubble.textContent.includes('图像生成失败')) {
      bubble.classList.add('bg-red-50');
    }
  });
  
  // 检查是否有API凭据，如果没有并且没有历史消息，则显示API设置模态框
  if (!hasApiCredentials && MessageManager.messages.length === 0) {
    // 延迟显示，确保DOM完全加载
    setTimeout(() => {
      apiKeyModal.classList.add('show');
    }, 500);
  }
});

// 创建图像模板
const createImageTemplate = () => {
  const template = document.createElement('template');
  template.innerHTML = `
    <div class="flex parsedown justify-start">
      <div class="max-w-[80%] assistant-bubble">
        <div class="image-container"></div>
      </div>
    </div>
  `;
  return template;
};

// 清除历史模态框处理
const clearHistoryModal = document.getElementById('clear-history-modal');
const clearHistoryBtn = document.getElementById('clear-history-btn');
const closeClearHistoryBtn = document.getElementById('close-history-modal');
const cancelClearBtn = document.getElementById('cancel-clear');
const confirmClearBtn = document.getElementById('confirm-clear');

// 打开清除历史模态框
clearHistoryBtn.addEventListener('click', (e) => {
  e.preventDefault();
  clearHistoryModal.classList.add('show');
});

// 关闭清除历史模态框
closeClearHistoryBtn.addEventListener('click', () => {
  clearHistoryModal.classList.remove('show');
});

// 取消清除历史
cancelClearBtn.addEventListener('click', () => {
  clearHistoryModal.classList.remove('show');
});

// 点击模态框外部关闭
window.addEventListener('click', (e) => {
  if (e.target === clearHistoryModal) {
    clearHistoryModal.classList.remove('show');
  }
});

// 确认清除历史
confirmClearBtn.addEventListener('click', async () => {
  try {
    // 使用MessageManager清除消息
    MessageManager.clearMessages();
    
    // 发送清除历史的请求
    const response = await fetch(`${window.location.href}?clear_history=1`, {
      method: 'GET',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const result = await response.json();
    
    if (result.success) {
      // 重置表单
      messageInput.value = '';
      clearImage();
      submitButton.disabled = true;
      
      // 重置模型选择为默认聊天模型
      ModelManager.setModel(ModelManager.MODELS.CHAT);
      
      // 显示成功提示（可选）
      const assistantMsgDiv = assistantMessageTemplate.content.cloneNode(true).firstElementChild;
      const typewriterDiv = assistantMsgDiv.querySelector('.typewriter');
      typewriterDiv.textContent = "聊天历史已清除，我们可以开始新的对话了！";
      messagesContainer.appendChild(assistantMsgDiv);
      
      // 将此消息添加到历史
      MessageManager.addMessage("assistant", [{
        type: "text",
        text: "聊天历史已清除，我们可以开始新的对话了！"
      }]);
      
      // 关闭模态框
      clearHistoryModal.classList.remove('show');
    }
  } catch (error) {
    console.error('清除历史错误:', error);
    alert('清除历史时出错，请刷新页面重试。');
    clearHistoryModal.classList.remove('show');
  }
});

// 调试信息
console.log('Grok聊天应用已加载 - 已添加AI绘图功能');

// 更新表单的replicated value来处理自动调整高度
const updateReplicatedValue = (textarea) => {
  // 动态调整输入框高度 - 简化版本
  textarea.style.height = 'auto'; // 先重置高度
  textarea.style.height = Math.min(textarea.scrollHeight, 200) + 'px'; // 调整到内容高度，最大200px
};

// API密钥模态框处理
const apiKeyModal = document.getElementById('api-key-modal');
const apiKeyBtn = document.getElementById('api-key-btn');
const closeModalBtn = document.getElementById('close-modal');
const apiCredentialsForm = document.getElementById('api-credentials-form');

// 检查是否已有凭据，如果有则填充表单
const initCredentialsForm = () => {
  if (CredentialsManager.hasCredentials()) {
    const { apiKey, accountId } = CredentialsManager.getCredentials();
    document.getElementById('api-key').value = apiKey || '';
    document.getElementById('account-id').value = accountId || '';
  }
};

// 打开模态框
apiKeyBtn.addEventListener('click', () => {
  initCredentialsForm();
  apiKeyModal.classList.add('show');
});

// 关闭模态框
closeModalBtn.addEventListener('click', () => {
  apiKeyModal.classList.remove('show');
});

// 点击模态框外部关闭
window.addEventListener('click', (e) => {
  if (e.target === apiKeyModal) {
    apiKeyModal.classList.remove('show');
  }
});

// 提交API凭据
apiCredentialsForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  
  const formData = new FormData(apiCredentialsForm);
  const apiKey = formData.get('api_key');
  const accountId = formData.get('account_id');
  
  // 保存凭据到localStorage
  CredentialsManager.saveCredentials(apiKey, accountId);
  
  try {
    // 发送保存凭据的请求
    const response = await fetch(window.location.href, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new FormData(apiCredentialsForm)
    });
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const result = await response.json();
    
    if (result.success) {
      // 显示成功消息
      const successMessage = document.createElement('div');
      successMessage.className = 'mt-2 p-2 bg-green-100 text-green-700 rounded-md';
      successMessage.textContent = '凭据已成功保存！';
      
      // 如果已经有成功消息，先移除
      const existingMessage = apiCredentialsForm.querySelector('.bg-green-100');
      if (existingMessage) {
        existingMessage.remove();
      }
      
      // 添加成功消息
      apiCredentialsForm.appendChild(successMessage);
      
      // 更新API凭据状态
      hasApiCredentials = true;
      
      // 1秒后自动关闭模态框
      setTimeout(() => {
        apiKeyModal.classList.remove('show');
        
        // 如果是因为提交表单而弹出的模态框，在关闭后自动提交表单
        if (messageInput.value.trim() || document.querySelector('input[type=file]').files[0]) {
          chatForm.dispatchEvent(new Event('submit'));
        }
      }, 1000);
    }
  } catch (error) {
    console.error('保存API凭据错误:', error);
    
    // 显示错误消息
    const errorMessage = document.createElement('div');
    errorMessage.className = 'mt-2 p-2 bg-red-100 text-red-700 rounded-md';
    errorMessage.textContent = '保存凭据失败，请重试。';
    
    // 如果已经有错误消息，先移除
    const existingMessage = apiCredentialsForm.querySelector('.bg-red-100');
    if (existingMessage) {
      existingMessage.remove();
    }
    
    // 添加错误消息
    apiCredentialsForm.appendChild(errorMessage);
  }
});

// 初始化页面
document.addEventListener('DOMContentLoaded', () => {
  // 检查浏览器是否支持localStorage
  try {
    const test = '__test__';
    localStorage.setItem(test, test);
    localStorage.removeItem(test);
  } catch (e) {
    alert('您的浏览器不支持或禁用了localStorage，可能无法正常使用。');
  }
});
</script>
</body>
</html>