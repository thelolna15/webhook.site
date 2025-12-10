#!/bin/bash

# Script untuk deploy update fitur server-side redirect

echo "========================================="
echo "  Deploy Server-Side Redirect Feature"
echo "========================================="
echo ""

echo "Step 1: Stop running containers..."
docker-compose down

echo ""
echo "Step 2: Build image dengan dependency baru..."
docker-compose build --no-cache webhook-site

echo ""
echo "Step 3: Install composer dependencies..."
docker-compose run --rm webhook-site composer install

echo ""
echo "Step 4: Start all services..."
docker-compose up -d

echo ""
echo "Step 5: Wait for services to start..."
sleep 10

echo ""
echo "Step 6: Check services status..."
docker-compose ps

echo ""
echo "========================================="
echo "  Deployment Complete!"
echo "========================================="
echo ""
echo "Access aplikasi di: http://localhost:8084"
echo ""
echo "Test API endpoints:"
echo "  - Toggle: PUT /token/{id}/server-redirect/toggle"
echo "  - Update: PUT /token/{id}/server-redirect"
echo ""

