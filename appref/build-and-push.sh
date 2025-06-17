#!/bin/bash
set -e

# Configuration
IMAGE_NAME="localhost:5000/test-php"
TAG="latest"
PORT=8021

# Build the Docker image
echo "Building Docker image: $IMAGE_NAME:$TAG"
docker build -t $IMAGE_NAME:$TAG .

# Push the image to the local registry
echo "Pushing image to local registry at localhost:5000"
docker push $IMAGE_NAME:$TAG

echo "Done! Image is available at $IMAGE_NAME:$TAG"
