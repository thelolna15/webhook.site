#!/bin/bash

# Script untuk deploy update fitur server-side redirect

echo "========================================="
echo "  Deploy Server-Side Redirect Feature"
echo "========================================="
echo ""

echo "Step 1: Stop running containers..."
docker-compose down

echo ""
echo "Step 2: Clean up old images (optional)..."
docker image prune -f

echo ""
echo "Step 3: Build image dengan dependency baru..."
echo "   - Upgrade Composer 2"
echo "   - Install Guzzle HTTP Client"
echo "   - Compile frontend assets"
docker-compose build --no-cache webhook-site

echo ""
echo "Step 4: Start all services..."
docker-compose up -d

echo ""
echo "Step 5: Wait for services to start..."
echo "   Waiting 15 seconds..."
sleep 15

echo ""
echo "Step 6: Check services status..."
docker-compose ps

echo ""
echo "Step 7: Verify webhook-site logs..."
docker logs webhook-site --tail 20

echo ""
echo "========================================="
echo "  Deployment Complete!"
echo "========================================="
echo ""
echo "✅ Access aplikasi di: http://localhost:8084"
echo ""
echo "🧪 Test API endpoints:"
echo "  - Toggle: PUT /token/{id}/server-redirect/toggle"
echo "  - Update: PUT /token/{id}/server-redirect"
echo ""
echo "📋 View logs:"
echo "  - All: docker logs webhook-site -f"
echo "  - Redirect: docker logs webhook-site -f | grep 'Server Redirect'"
echo ""

