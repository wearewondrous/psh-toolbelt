# Use an official PHP image as the base
FROM php:8.1-cli

# Set the working directory inside the container
WORKDIR /psh-toolbelt

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy the composer.json and composer.lock files to the container
ADD composer.json composer.lock .

# Install dependencies using Composer
RUN composer install --no-scripts --no-autoloader --no-progress --no-suggest

# Copy the rest of the application code to the container
# COPY . /psh-toolbelt
ADD . /psh-toolbelt

# Generate the autoloader
RUN composer dump-autoload --optimize

# Set the entrypoint command to run PHP commands
ENTRYPOINT [ "php" ]

#docker run -it -v /Users/welldev/Desktop/Wondrous/psh-toolbelt:/psh psh