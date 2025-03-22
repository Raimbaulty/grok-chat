# Use a lightweight PHP image
FROM php:8.2-alpine

# Set working directory
WORKDIR /var/www/html

# Copy PHP files
COPY index.php .
COPY Parsedown.php .
COPY parsedown.css .

# Expose port 8000 for Render
EXPOSE 8000

# Start the built-in PHP server
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html"]
