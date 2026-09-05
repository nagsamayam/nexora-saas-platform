FROM nginx:alpine-slim

# Copy your production-ready Nginx configuration into the image
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

# Run Nginx in the foreground so the container doesn't immediately exit
CMD ["nginx", "-g", "daemon off;"]