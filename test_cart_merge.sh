#!/bin/bash

API_URL="http://localhost:8000"
SITE_ID=56

echo "========================================="
echo "TEST COMPLET: Fusion de 2 paniers"
echo "========================================="
echo ""

# 0. PREPARATION : Vider le panier existant
echo "0. Préparation : Connexion et vidage panier..."
AUTH_RESPONSE=$(curl -s -X POST "$API_URL/api/v1/login_check" \
  -H "Content-Type: application/json" \
  -H "X-Site-Id: $SITE_ID" \
  -d '{
    "email": "victoire69@barbier.com",
    "password": "Password123!"
  }')

JWT_TOKEN=$(echo $AUTH_RESPONSE | jq -r '.token')

# Vider le panier (DELETE /api/v1/carts si tu as cette route, sinon supprime les items un par un)
curl -s -X DELETE "$API_URL/api/v1/carts" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "X-Site-Id: $SITE_ID" > /dev/null

echo "   → Panier vidé"
echo ""

# 1. User se connecte et ajoute un produit
echo "1. User se connecte..."
AUTH_RESPONSE=$(curl -s -X POST "$API_URL/api/v1/login_check" \
  -H "Content-Type: application/json" \
  -H "X-Site-Id: $SITE_ID" \
  -d '{
    "email": "victoire69@barbier.com",
    "password": "Password123!"
  }')

JWT_TOKEN=$(echo $AUTH_RESPONSE | jq -r '.token')
echo "   → JWT: ${JWT_TOKEN:0:50}..."

echo ""
echo "2. User ajoute un produit A (Huile d'Olive variant 263)..."
ADD_A_RESPONSE=$(curl -s -X POST "$API_URL/api/v1/carts/items" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "X-Site-Id: $SITE_ID" \
  -H "X-Currency: EUR" \
  -H "X-Locale: fr" \
  -d '{
    "variant_id": 260,
    "quantity": 1
  }')

# Afficher la réponse complète pour debug
echo "📋 Réponse complète:"
echo "$ADD_A_RESPONSE" | jq '.'

SUMMARY_A=$(echo "$ADD_A_RESPONSE" | jq '.cart.summary')
if [ "$SUMMARY_A" == "null" ]; then
    echo "   ❌ ERREUR : Produit A non ajouté !"
    echo "   Vérifier que variant_id 263 existe"
    exit 1
fi
echo "$SUMMARY_A" | jq '.'

echo ""
echo "3. User se déconnecte (simulation)..."
echo "   → On oublie le JWT"

echo ""
echo "4. Invité ajoute un produit B (Lavande variant 262)..."
GUEST_RESPONSE=$(curl -s -X POST "$API_URL/api/v1/carts/items" \
  -H "Content-Type: application/json" \
  -H "X-Site-Id: $SITE_ID" \
  -H "X-Currency: EUR" \
  -H "X-Locale: fr" \
  -d '{
    "variant_id": 262,
    "quantity": 2
  }')

GUEST_TOKEN=$(echo $GUEST_RESPONSE | jq -r '.token')
echo "   → Guest Token: $GUEST_TOKEN"
echo "$GUEST_RESPONSE" | jq '.cart.summary'

echo ""
echo "5. User se reconnecte..."
AUTH_RESPONSE=$(curl -s -X POST "$API_URL/api/v1/login_check" \
  -H "Content-Type: application/json" \
  -H "X-Site-Id: $SITE_ID" \
  -d '{
    "email": "victoire69@barbier.com",
    "password": "Password123!"
  }')

JWT_TOKEN=$(echo $AUTH_RESPONSE | jq -r '.token')
echo "   → Nouveau JWT: ${JWT_TOKEN:0:50}..."

echo ""
echo "6. Fusion des paniers..."
MERGE_RESPONSE=$(curl -s -X POST "$API_URL/api/v1/carts/merge" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "X-Site-Id: $SITE_ID" \
  -d "{
    \"guest_token\": \"$GUEST_TOKEN\"
  }")

echo "$MERGE_RESPONSE" | jq '.'

echo ""
echo "7. Vérification finale..."
CART_RESPONSE=$(curl -s -X GET "$API_URL/api/v1/carts" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "X-Site-Id: $SITE_ID")

echo "📋 Détail des items:"
echo "$CART_RESPONSE" | jq '.cart.items[] | {name: .name, quantity: .quantity}'

ITEMS_COUNT=$(echo $CART_RESPONSE | jq -r '.cart.summary.items_count')
LINES_COUNT=$(echo $CART_RESPONSE | jq -r '.cart.summary.lines_count')

echo ""
echo "   → Lignes: $LINES_COUNT (attendu: 2)"
echo "   → Items: $ITEMS_COUNT (attendu: 3)"

if [ "$ITEMS_COUNT" -eq 3 ] && [ "$LINES_COUNT" -eq 2 ]; then
    echo "   ✅ TEST RÉUSSI - Fusion complète !"
else
    echo "   ❌ Fusion incomplète"
fi