# 🏗️ Architecture E-commerce Produits Bio - Document Technique

## 📋 Vue d'ensemble

Cette architecture suit les principes **DDD (Domain-Driven Design)** en organisant les entités par **domaines métiers cohérents**. 
Chaque domaine est autonome et communique avec les autres via des relations clairement définies.

### Domaines métiers identifiés

```
┌─────────────────────────────────────────────────────────────────┐
│                     E-COMMERCE PRODUITS BIO                     │
└─────────────────────────────────────────────────────────────────┘
         │
         ├─── 🏪 DOMAINE SITE (Multi-tenant)
         │
         ├─── 📦 DOMAINE PRODUIT (Catalogue)
         │
         ├─── 👤 DOMAINE UTILISATEUR (Identity & Access)
         │
         ├─── 🛒 DOMAINE PANIER (Shopping Cart)
         │
         ├─── 📋 DOMAINE COMMANDE (Order Management)
         │
         ├─── 💳 DOMAINE PAIEMENT (Payment)
         │
         ├─── 🚚 DOMAINE LIVRAISON (Shipping)
         │
         ├─── ⭐ DOMAINE AVIS (Reviews & Ratings)
         │
         ├─── 📰 DOMAINE CONTENU (CMS)
         │
         ├─── 🎫 DOMAINE PROMOTION (Coupons & Discounts)
         │
         └─── ⚙️ DOMAINE CONFIGURATION (Settings)
```

---

┌─────────────────────────────────────────────────────────────────┐
│                     FLUX DE PAIEMENT STRIPE                      │
└─────────────────────────────────────────────────────────────────┘

1️⃣ CRÉATION DE LA COMMANDE (Frontend Nuxt)
   ┌──────────┐
   │  Client  │─── POST /orders ──→ 📡 API Symfony
   └──────────┘                         │
                                        ├─ Créé Order (status: PENDING)
                                        ├─ Créé Payment (status: PENDING)
                                        ├─ Appelle Stripe API pour créer PaymentIntent
                                        │
   ┌──────────┐                         │
   │  Client  │←── Retourne client_secret ─┘
   └──────────┘
        │
        │ 2️⃣ PAIEMENT CÔTÉ CLIENT
        │
        └──→ Stripe.js (dans le navigateur)
             │
             └──→ Stripe traite le paiement (carte, 3DS, etc.)


3️⃣ NOTIFICATION ASYNCHRONE (Webhooks Stripe)
   
   ┌──────────────┐
   │ Stripe Server│──── POST /webhook/stripe ──→ 📡 API Symfony
   └──────────────┘                                   │
                                                      ├─ Vérifie signature Stripe
                                                      ├─ Met à jour Payment (SUCCESS/FAILED)
                                                      ├─ Met à jour Order (CONFIRMED/CANCELLED)
                                                      └─ Envoie email de confirmation


4️⃣ CONFIRMATION CÔTÉ CLIENT (Optionnel - améliore UX)

   ┌──────────┐
   │  Client  │──── Polling GET /orders/{id} ──→ 📡 API Symfony
   └──────────┘     (toutes les 2 secondes pendant 30s)
        │                                              │
        │                                              └─ Retourne status actuel
        │
        ├─ Si status = CONFIRMED → Affiche "✅ Paiement réussi !"
        └─ Si status = PENDING après 30s → Affiche "⏳ En attente, vous recevrez un email"

## 🏪 DOMAINE SITE (Multi-tenant)

### Entité : Site

**Rôle métier :** Permet de gérer plusieurs boutiques indépendantes sur la même infrastructure (multi-tenant).

**Pourquoi cette entité ?**
- Tu peux avoir plusieurs boutiques (site FR, site BE, site revendeur...)
- Chaque site a sa propre configuration (devise, langues, thème)
- Isolation des données clients/produits par site
- Économie d'infrastructure (1 seul backend)

**Relations :**
```
Site (1) ──── (N) Product
     (1) ──── (N) Category
     (1) ──── (N) User
     (1) ──── (N) Order
     (1) ──── (N) Page (CMS)
```

**Pourquoi ces relations ?**
- **OneToMany avec Product** : Un produit appartient à UN site (pas de produits partagés entre sites)
- **OneToMany avec User** : Un client est lié à UN site (comptes séparés)
- Alternative écartée : ManyToMany → Compliquerait la gestion des prix/stocks

**Cas d'usage concret :**
- Site A : Boutique FR (EUR, français)
- Site B : Boutique BE (EUR, français/néerlandais)
- Site C : Revendeur professionnel (prix grossiste)

---

## 📦 DOMAINE PRODUIT (Catalogue)

### Entité : Product

**Rôle métier :** Représente un produit "parent" (ex: Miel de Fleurs Bio).

**Pourquoi cette entité séparée des variantes ?**
- Le produit porte les informations **communes** à toutes les variantes (description, images, bienfaits)
- Permet une URL unique : `/produits/miel-de-fleurs-bio`
- Facilite le SEO (1 page = 1 produit, plusieurs formats)
- Simplifie la navigation client (grouper les formats ensemble)

**Relations principales :**
```
Product (N) ──── (N) Category [ManyToMany via product_categories]
        (1) ──── (N) ProductVariant
        (1) ──── (N) Review
        (1) ──── (1) Site
```

**Attributs clés stockés en JSON :**
- `images` : Flexible, pas besoin de table dédiée pour < 10 images
- `attributes` : Origine, certifications, bienfaits (spécifique bio)
- `nutritionalValues` : Valeurs nutritionnelles

---

### Entité : ProductVariant

**Rôle métier :** Représente un format/volume spécifique (Pot 250g, Pot 500g, Pot 1kg).

**Pourquoi une entité dédiée et pas juste un champ ?**

✅ **Avantages d'avoir ProductVariant séparé :**
- Stock indépendant par format
- Prix différent par format (économie d'échelle)
- SKU/code-barres unique par format
- Promotions ciblées (promo sur 500g uniquement)
- Historique : si un format est supprimé, les anciennes commandes restent cohérentes

❌ **Alternative écartée : Tout dans Product :**
```
Product avec champs : stock_250g, price_250g, stock_500g, price_500g...
→ Rigide, impossible d'ajouter un nouveau format sans migration
→ Duplication de logique
→ Impossible de gérer l'historique
```

**Relations :**
```
ProductVariant (N) ──── (1) Product
               (1) ──── (N) CartItem
               (1) ──── (N) OrderItem
```

**Pourquoi ManyToOne avec Product ?**
- Une variante appartient à UN SEUL produit (pas de réutilisation)
- Le produit parent peut avoir N variantes

**Pourquoi lié à CartItem et OrderItem ?**
- On achète une VARIANTE précise, pas juste "un miel"
- Traçabilité : savoir exactement quel format a été commandé
- Stock : décrémenter le bon format

---

### Entité : Category

**Rôle métier :** Organiser les produits par catégories (Miels, Huiles, Sirops...).

**Pourquoi ManyToMany avec Product ?**
- Un produit peut être dans plusieurs catégories
  - Ex: "Miel Bio" peut être dans "Miels" ET "Produits certifiés AB"
- Une catégorie contient plusieurs produits

❌ **Alternative écartée : OneToMany (1 catégorie par produit) :**
- Trop limitant pour l'organisation
- Impossible de faire des collections croisées

**Structure hiérarchique (self-referencing) :**
```
Category (N) ──── (1) Category [parent]

Exemple :
- Alimentaire (parent: null)
  ├─ Miels (parent: Alimentaire)
  │  ├─ Miel de Fleurs (parent: Miels)
  │  └─ Miel d'Acacia (parent: Miels)
  └─ Huiles (parent: Alimentaire)
```

**Pourquoi cette approche ?**
- Navigation intuitive (breadcrumbs)
- SEO : URLs structurées `/alimentaire/miels/miel-de-fleurs`
- Filtrage facilité

---

## 👤 DOMAINE UTILISATEUR (Identity & Access)

### Entité : User

**Rôle métier :** Représente un utilisateur de la plateforme (client ou admin).

**Champs essentiels :**
- `email` (unique par site)
- `password` (hashé)
- `roles` (JSON : ['ROLE_USER'] ou ['ROLE_ADMIN'])
- `isActive` (désactivation compte)

**Relations :**
```
User (1) ──── (1) Cart
     (1) ──── (N) Order
     (1) ──── (N) Address
     (1) ──── (N) Review
     (1) ──── (1) Site
```

**Pourquoi OneToOne avec Cart ?**
- Chaque utilisateur a UN SEUL panier actif
- Le panier persiste entre les sessions
- Simplifie la logique métier

❌ **Alternative écartée : OneToMany (plusieurs paniers) :**
- Complexifie inutilement
- Cas rare : l'utilisateur veut 2 paniers différents → gérer via "listes d'envies" à la place

**Pourquoi OneToMany avec Address ?**
- Un client peut avoir plusieurs adresses (domicile, travail, parents...)
- Mais une commande a UNE adresse de livraison et UNE de facturation

---

### Entité : Address

**Rôle métier :** Stocker les adresses (livraison et facturation).

**Pourquoi une entité séparée ?**
- Réutilisation : l'utilisateur choisit une adresse existante
- Évite la duplication dans Order (copie l'adresse au moment de la commande)
- Historique : si l'adresse est modifiée, les anciennes commandes gardent l'adresse d'origine

**Relations :**
```
Address (N) ──── (1) User
        (N) ──── (1) Country [optionnel, pour calcul frais de port]
```

**Structure recommandée :**
```
- street
- additional_address (complément)
- postal_code
- city
- country_code (ISO : FR, BE, CH...)
- phone
- is_default (adresse par défaut)
- type (billing, shipping)
```

---

## 🛒 DOMAINE PANIER (Shopping Cart)

### Entité : Cart

**Rôle métier :** Panier temporaire de l'utilisateur.

**Pourquoi une entité dédiée et pas une session ?**

✅ **Avantages de Cart en BDD :**
- Persistance multi-device (mobile → desktop)
- Pas de perte si session expire
- Panier invité récupérable après inscription
- Analytics : taux d'abandon, produits populaires

❌ **Alternative écartée : Session/Cookie uniquement :**
- Perte de données si cookie supprimé
- Impossible de synchroniser entre devices
- Pas de statistiques d'abandon

**Relations :**
```
Cart (1) ──── (1) User [nullable pour invités]
     (1) ──── (N) CartItem
```

**Pourquoi OneToOne avec User nullable ?**
- Un utilisateur authentifié a UN panier
- Un invité a UN panier (identifié par session_id)
- À l'inscription : fusion du panier invité avec le compte

---

### Entité : CartItem

**Rôle métier :** Ligne dans le panier (produit + variante + quantité).

**Relations :**
```
CartItem (N) ──── (1) Cart
         (N) ──── (1) Product
         (N) ──── (1) ProductVariant [IMPORTANT]
```

**Pourquoi lié à ProductVariant et pas juste Product ?**
- On achète un format précis (Pot 500g, pas juste "miel")
- Permet de vérifier le stock de la variante
- Prix peut différer selon la variante

**Champs importants :**
- `quantity` : Quantité voulue
- `price_at_add` : Prix au moment de l'ajout (évite les surprises si le prix change)

**Pourquoi stocker price_at_add ?**
- Si le prix du produit change pendant que le client navigue
- Le panier garde le prix initial → meilleure UX
- À la commande : recalcul avec prix actuel si différence significative

---

## 📋 DOMAINE COMMANDE (Order Management)

### Entité : Order

**Rôle métier :** Représente une commande validée.

**Relations :**
```
Order (N) ──── (1) User
      (1) ──── (N) OrderItem
      (1) ──── (1) Payment
      (1) ──── (1) Shipment
      (1) ──── (1) Site
```

**Pourquoi copier les adresses dans Order ?**
```
Order contient :
- shipping_address (JSON copié depuis Address)
- billing_address (JSON copié depuis Address)
```

**Raison :** Immutabilité historique
- Si le client modifie son adresse plus tard, la commande garde l'adresse d'origine
- Obligation légale (preuve de livraison)

**Statuts de commande (enum ou constantes) :**
```
- PENDING : En attente de paiement
- PAID : Payée
- PROCESSING : En préparation
- SHIPPED : Expédiée
- DELIVERED : Livrée
- CANCELLED : Annulée
- REFUNDED : Remboursée
```

**Pourquoi un champ status et pas des booléens ?**
- État unique et clair (pas de confusion)
- Facilite les transitions d'état (machine à états)
- Filtrage simple : "Toutes les commandes SHIPPED"

---

### Entité : OrderItem

**Rôle métier :** Ligne de commande (snapshot au moment de la commande).

**Pourquoi une entité séparée ?**
- Une commande a plusieurs lignes
- Chaque ligne = un produit acheté

**Relations :**
```
OrderItem (N) ──── (1) Order
          (N) ──── (1) Product [nullable]
          (N) ──── (1) ProductVariant [nullable]
```

**Pourquoi les relations sont nullables ?**
- Si un produit est supprimé après la commande, l'historique reste intact
- On COPIE les données importantes dans OrderItem :
  ```
  - product_name : "Miel de Fleurs Bio"
  - variant_name : "Pot 500g"
  - price : 14.99 (prix au moment de l'achat)
  - quantity : 2
  ```

**Alternative écartée : Juste stocker product_id :**
❌ Si le produit est supprimé → OrderItem casse
❌ Si le prix change → impossible de savoir le prix d'achat
✅ Snapshot = immuabilité garantie

---

## 💳 DOMAINE PAIEMENT (Payment)

### Entité : Payment

**Rôle métier :** Représente un paiement (Stripe, PayPal...).

**Relations :**
```
Payment (1) ──── (1) Order
```

**OneToOne avec Order car :**
- 1 commande = 1 paiement principal
- Si remboursement → nouveau Payment avec type=REFUND

**Champs importants :**
```
- amount : Montant payé
- currency : EUR, USD...
- payment_method : stripe, paypal, bank_transfer
- status : pending, completed, failed, refunded
- provider_transaction_id : ID Stripe/PayPal
- provider_data : JSON (données brutes du provider)
```

**Pourquoi provider_data en JSON ?**
- Chaque provider (Stripe, PayPal) retourne des structures différentes
- Permet de garder toutes les infos sans créer 50 colonnes
- Debug facilité (toutes les données du webhook)

---

## 🚚 DOMAINE LIVRAISON (Shipping)

### Entité : Shipment

**Rôle métier :** Suivi de l'expédition physique.

**Relations :**
```
Shipment (1) ──── (1) Order
         (N) ──── (1) ShippingMethod
```

**Pourquoi OneToOne avec Order ?**
- 1 commande = 1 expédition (pour simplifier)
- Si tu veux permettre plusieurs colis → passer en OneToMany plus tard

**Champs importants :**
```
- tracking_number : Numéro de suivi (Colissimo, Chronopost...)
- carrier : "Colissimo", "Chronopost", "UPS"
- status : pending, shipped, in_transit, delivered
- shipped_at : Date d'expédition
- delivered_at : Date de livraison
```

---

### Entité : ShippingMethod

**Rôle métier :** Méthode de livraison disponible (Colissimo, Point Relais...).

**Pourquoi une entité séparée ?**
- Configuration centralisée (1 endroit pour modifier les tarifs)
- Activation/désactivation facile
- Calcul de prix selon le poids

**Exemple de méthodes :**
```
- Colissimo Domicile : 0-2kg = 6.90€, 2-5kg = 9.90€
- Point Relais : 0-5kg = 4.90€
- Chronopost 24h : 0-2kg = 14.90€
```

**Relations :**
```
ShippingMethod (1) ──── (N) Shipment
               (1) ──── (1) Site
```

**Pourquoi lié au Site ?**
- Chaque site peut avoir ses propres méthodes de livraison
- Tarifs différents par pays

---

## ⭐ DOMAINE AVIS (Reviews & Ratings)

### Entité : Review

**Rôle métier :** Avis client sur un produit.

**Relations :**
```
Review (N) ──── (1) Product
       (N) ──── (1) User
       (N) ──── (1) Order [optionnel, pour vérifier l'achat]
```

**Pourquoi lié à Order ?**
- Permet de vérifier que le client a bien acheté le produit ("Achat vérifié")
- Évite les faux avis
- Un avis par commande contenant ce produit

**Champs :**
```
- rating : Note de 1 à 5
- title : Titre de l'avis
- comment : Commentaire
- is_verified_purchase : Booléen (a acheté le produit)
- is_approved : Modération
- helpful_count : Nombre de "Utile"
```

**Pourquoi is_approved ?**
- Modération des avis avant publication
- Protection contre spam/insultes

---

## 📰 DOMAINE CONTENU (CMS)

### Entité : Page

**Rôle métier :** Pages statiques éditables (Qui sommes-nous, CGV, Mentions légales...).

**Relations :**
```
Page (N) ──── (1) Site
```

**Champs :**
```
- title : Titre de la page
- slug : URL (ex: "qui-sommes-nous")
- content : Contenu HTML
- locale : Langue (fr, en, es...)
- is_published : Publié ou brouillon
- seo_title, seo_description : SEO
```

**Pourquoi une entité dédiée ?**
- L'admin peut créer/modifier sans toucher au code
- Support multilingue (1 page par langue)
- Versionning possible (garder historique des modifications)

---

### Entité : MenuItem

**Rôle métier :** Items du menu de navigation (Header/Footer).

**Relations :**
```
MenuItem (N) ──── (1) MenuItem [parent, self-referencing]
         (N) ──── (1) Site
```

**Structure hiérarchique :**
```
- Produits (parent)
  ├─ Miels
  ├─ Huiles
  └─ Sirops
- À propos (parent)
  ├─ Qui sommes-nous
  └─ Nos engagements
```

**Champs :**
```
- label : Texte affiché
- url : Lien (/produits ou page externe)
- target : _self, _blank
- position : Ordre d'affichage
- is_active : Visible ou caché
```

---

## 🎫 DOMAINE PROMOTION (Coupons & Discounts)

### Entité : Coupon

**Rôle métier :** Code promo (ex: BIENVENUE10 = -10%).

**Relations :**
```
Coupon (N) ──── (N) Category [optionnel, limitation par catégorie]
       (N) ──── (N) Product [optionnel, limitation par produit]
       (1) ──── (N) Order [usage]
       (1) ──── (1) Site
```

**Champs :**
```
- code : "BIENVENUE10" (unique par site)
- type : percentage, fixed_amount
- value : 10 (10% ou 10€)
- min_order_amount : Montant minimum de commande
- max_uses : Nombre d'utilisations max
- current_uses : Compteur d'utilisations
- valid_from, valid_until : Période de validité
- is_active : Activé/désactivé
```

**Pourquoi ManyToMany avec Category/Product ?**
- Permet de créer des promos ciblées
  - "10€ de réduction sur les miels"
  - "15% sur Miel de Fleurs Bio uniquement"
- Si vides → promo sur tout le site

---

## ⚙️ DOMAINE CONFIGURATION (Settings)

### Entité : SiteSettings

**Rôle métier :** Configuration globale du site (design, SEO, contact...).

**Relations :**
```
SiteSettings (1) ──── (1) Site
```

**Pourquoi OneToOne et pas des colonnes dans Site ?**
- Séparer les données fixes (Site) des données configurables (Settings)
- Settings change souvent, Site rarement
- Peut avoir plusieurs versions (staging vs production)

**Champs stockés en JSON :**
```json
{
  "theme": {
    "primary_color": "#2E7D32",
    "logo_url": "https://cdn.com/logo.png",
    "favicon_url": "https://cdn.com/favicon.ico"
  },
  "contact": {
    "email": "contact@maboutique.com",
    "phone": "+33 1 23 45 67 89",
    "address": "123 Rue Bio, 75001 Paris"
  },
  "seo": {
    "default_title": "Ma Boutique Bio",
    "default_description": "Produits bio...",
    "google_analytics_id": "UA-123456"
  },
  "social": {
    "facebook": "https://facebook.com/...",
    "instagram": "https://instagram.com/..."
  }
}
```

---

## 🔗 RELATIONS TRANSVERSALES IMPORTANTES

### Product ↔ CartItem ↔ OrderItem

**Flux métier :**
```
1. Client ajoute Miel 500g au panier
   → CartItem créé (lié à Product + ProductVariant)

2. Client valide le panier
   → Order créé
   → OrderItem créé (COPIE les données de CartItem)
   → CartItem supprimé

3. Plus tard, le produit est supprimé
   → Product marqué isActive=false
   → OrderItem conserve product_name="Miel de Fleurs Bio"
   → Historique intact
```

**Pourquoi cette chaîne ?**
- **CartItem** = État temporaire, modifiable
- **OrderItem** = État figé, historique immuable

---

### User ↔ Address ↔ Order

**Flux métier :**
```
1. Utilisateur crée une adresse
   → Address créée, liée à User

2. Lors de la commande, Order copie l'adresse
   → Order.shipping_address = JSON(Address)
   → Order.billing_address = JSON(Address)

3. Plus tard, User modifie son adresse
   → Address mise à jour
   → Order garde l'ancienne adresse (immutabilité)
```

**Pourquoi copier et pas juste référencer Address ?**
- Preuve légale (adresse de livraison doit être immuable)
- Si l'utilisateur déménage, les anciennes commandes restent cohérentes

---

## 📊 SCHÉMA RELATIONNEL GLOBAL

```
┌──────────────────────────────────────────────────────────────────┐
│                         CORE ENTITIES                            │
└──────────────────────────────────────────────────────────────────┘

Site (1)────(N) Product (1)────(N) ProductVariant
  │                │                      │
  │                │                      ├──(N) CartItem
  │                │                      └──(N) OrderItem
  │                │
  │                ├────(N,N) Category
  │                └────(N) Review (N)────(1) User
  │                                             │
  ├────(N) User (1)────(1) Cart (1)────(N) CartItem
  │         │
  │         ├────(N) Address
  │         ├────(N) Order (1)────(N) OrderItem
  │         │                │
  │         │                ├────(1) Payment
  │         │                └────(1) Shipment (N)────(1) ShippingMethod
  │         │
  │         └────(N) Review
  │
  ├────(N) Category
  ├────(N) Page
  ├────(N) MenuItem
  ├────(N) Coupon
  ├────(N) ShippingMethod
  └────(1) SiteSettings
```

---

## 🎯 RÉCAPITULATIF DES CHOIX ARCHITECTURAUX

### 1. Product + ProductVariant (2 entités)
**Choix :** Séparé
**Raison :** Stock et prix indépendants par format, flexibilité

### 2. Cart + CartItem (2 entités)
**Choix :** Séparé
**Raison :** Persistance multi-device, analytics d'abandon

### 3. Order + OrderItem (2 entités)
**Choix :** Séparé avec snapshot
**Raison :** Immutabilité historique, indépendance du catalogue

### 4. Address copiée dans Order
**Choix :** Duplication (JSON dans Order)
**Raison :** Immutabilité légale, historique

### 5. Attributes en JSON (pas EAV)
**Choix :** JSON dans Product
**Raison :** Simplicité, flexibilité, performance

### 6. ManyToMany Product ↔ Category
**Choix :** ManyToMany
**Raison :** Un produit dans plusieurs catégories

### 7. OneToOne Cart ↔ User
**Choix :** OneToOne (nullable)
**Raison :** 1 panier actif par utilisateur, gestion des invités

---

## ✅ ESTIMATION TOTALE DES ENTITÉS

### Entités essentielles (MVP) : 15
1. Site
2. User
3. Address
4. Product
5. ProductVariant
6. Category
7. Cart
8. CartItem
9. Order
10. OrderItem
11. Payment
12. Shipment
13. ShippingMethod
14. Review
15. SiteSettings

### Entités avancées (Phase 2) : 4
16. Page (CMS)
17. MenuItem
18. Coupon
19. Wishlist (liste d'envies)

**Total : 19 entités** pour un e-commerce complet et professionnel.

---

## 🚀 ROADMAP D'IMPLÉMENTATION

### Phase 1 : Catalogue (Semaine 1-2)
- Site
- Product + ProductVariant
- Category

### Phase 2 : Utilisateurs (Semaine 2)
- User
- Address

### Phase 3 : Panier & Commandes (Semaine 3-4)
- Cart + CartItem
- Order + OrderItem

### Phase 4 : Paiement & Livraison (Semaine 4-5)
- Payment (Stripe)
- Shipment + ShippingMethod

### Phase 5 : Contenu & Promotions (Semaine 6)
- Page + MenuItem
- Coupon
- Review

---

## 💡 PRINCIPES RESPECTÉS

✅ **SRP (Single Responsibility)** : Chaque entité a un rôle clair
✅ **DRY (Don't Repeat Yourself)** : Réutilisation via relations
✅ **Immutabilité** : Les commandes sont figées (snapshot)
✅ **Scalabilité** : Multi-tenant via Site
✅ **Performance** : JSON pour flexibilité, pas d'over-engineering
✅ **Maintenabilité** : Architecture claire et logique
