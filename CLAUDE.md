# ServiceFlow — Contexte complet plugin

## Identité du projet

| Propriété | Valeur |
|---|---|
| Type | Plugin WordPress premium (freemium via Freemius) |
| Version | `1.0.0` — **NE PAS changer ce numéro de version** |
| Emplacement | `c:/Users/Ecompresta/Documents/Projets plugins/serviceflow/` |
| Repo GitHub | https://github.com/SylvestreUi/serviceflow |
| Statut | En test sur staging, pas encore soumis sur WordPress.org |
| Compatibilité | WP 5.8+, PHP 7.4+, testé jusqu'à WP 6.9 |
| Text domain | `serviceflow` |
| Freemius ID | `24444`, slug `serviceflow`, clé publique `pk_b46863119a89c13fd6d225cc981e1` |

---

## Architecture des fichiers

```
serviceflow.php                        ← Point d'entrée : init Freemius, chargement classes, hooks
readme.txt                             ← Fiche WordPress.org
phpcs.xml                              ← Config PHPCS (exclut lib/ et vendor/)
build.ps1                              ← Script PowerShell → génère dist/serviceflow-1.0.0.zip
dist/serviceflow-1.0.0.zip             ← Archive de distribution (rebuild après chaque modif)

includes/
  class-serviceflow-db.php             ← Table chat (messages), migrations client_id
  class-serviceflow-admin.php          ← Réglages plugin (WP Settings API) + feature flags
  class-serviceflow-ajax.php           ← Chat : envoyer/poller/charger messages
  class-serviceflow-front.php          ← Rendu frontend : widget chat + shortcode [serviceflow_options]
  class-serviceflow-options.php        ← Métabox admin : packs, options, prix avancés par service
  class-serviceflow-orders.php         ← Commandes, transitions de statut, AJAX orders
  class-serviceflow-dashboard.php      ← Dashboard admin : stats, liste commandes
  class-serviceflow-account.php        ← Page compte client → shortcode [serviceflow_my_account]
  class-serviceflow-notifications.php  ← Notifications in-app + emails (premium)
  class-serviceflow-invoices.php       ← Factures : CRUD, PDF, AJAX, clients externes
  class-serviceflow-stripe.php         ← Stripe Checkout : création session, webhook, retour
  class-serviceflow-payments.php       ← Échéanciers, calculs montants, cron paiements
  class-serviceflow-todos.php          ← Todo list par commande (premium), auto-complétion
  class-serviceflow-shortcodes.php     ← Shortcodes utilitaires (prix, délais)
  class-serviceflow-elementor.php      ← Dynamic Tags Elementor
  class-serviceflow-elementor-widgets.php ← Widgets Elementor

assets/
  js/serviceflow.js                    ← JS unique (chat, commandes, todos, paiements)
  css/serviceflow.css                  ← CSS unique

lib/                                   ← Stripe PHP SDK — NE PAS MODIFIER
vendor/freemius/                       ← SDK Freemius — NE PAS MODIFIER
```

---

## Tables de base de données

Toutes créées via `dbDelta()` à l'activation et mises à jour via le hook `admin_init` (comparaison `serviceflow_db_version` vs `SERVICEFLOW_VERSION`).

**Migration ponctuelle sans changement de version** : utiliser une option WP séparée (ex. `serviceflow_orders_extra_cols`) plutôt que de bumper la version.

### 1. `{prefix}serviceflow_messages` — Chat
| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT | PK auto-increment |
| post_id | BIGINT | ID du CPT service |
| client_id | BIGINT | ID WP user du client (0 = message admin/système) |
| user_id | BIGINT | Auteur du message |
| message | TEXT | Contenu |
| created_at | DATETIME | |

### 2. `{prefix}serviceflow_orders` — Commandes
| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT | PK |
| post_id | BIGINT | ID CPT service |
| client_id | BIGINT | ID WP user |
| status | VARCHAR(20) | pending/paid/started/completed/revision/accepted |
| base_offer | TEXT | JSON : `{name, price, delay, features[]}` |
| selected_options | TEXT | JSON : `[{name, price, delay}, ...]` |
| total_price | DECIMAL(10,2) | Total TTC du contrat complet |
| total_delay | INT | Délai en jours |
| estimated_date | DATE | Date estimée de livraison |
| stripe_session_id | VARCHAR(255) | ID session Stripe Checkout |
| stripe_payment_intent | VARCHAR(255) | ID PaymentIntent Stripe |
| payment_mode | VARCHAR(20) | single/deposit/installments/monthly |
| deposit_percent | TINYINT | 50 (deposit) ou 40 (installments) |
| installments_count | TINYINT | Nombre de mensualités |
| extra_pages | TINYINT | Nbre pages supplémentaires commandées |
| extra_page_price | DECIMAL(10,2) | Prix unitaire HT par page |
| maintenance_price | DECIMAL(10,2) | Prix maintenance mensuel HT |
| express_days | TINYINT | Jours express commandés |
| express_price | DECIMAL(10,2) | Prix HT par jour express |
| created_at / updated_at | DATETIME | |

### 3. `{prefix}serviceflow_notifications` — Notifications
| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT | PK |
| user_id | BIGINT | Destinataire |
| type | VARCHAR(50) | Type d'event |
| message | TEXT | |
| is_read | TINYINT | 0/1 |
| order_id | BIGINT | Lien commande |
| post_id | BIGINT | Lien service |
| created_at | DATETIME | |

### 4. `{prefix}serviceflow_invoices` — Factures
| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT | PK |
| invoice_number | VARCHAR(30) | UNIQUE, format FACT-XXX |
| order_id | BIGINT | NULL si facture externe |
| client_id | BIGINT | WP user ID (NULL si client externe) |
| ext_client_id | BIGINT | Client externe (NULL si WP user) |
| status | VARCHAR(20) | draft/pending/paid/cancelled |
| items | LONGTEXT | JSON : `[{service_name?, description, quantity, unit_price, total}]` |
| subtotal | DECIMAL(10,2) | Total HT |
| tax_rate | DECIMAL(5,2) | % TVA |
| tax_amount | DECIMAL(10,2) | Montant TVA |
| total | DECIMAL(10,2) | Total TTC |
| notes | TEXT | Conditions paiement |
| invoice_type | VARCHAR(30) | single/acompte/solde/mensualite |
| schedule_id | BIGINT | Lien ligne d'échéancier |
| created_at / updated_at / validated_at / paid_at | DATETIME | |

### 5. `{prefix}serviceflow_clients` — Clients externes (non WP users)
| Colonne | Type |
|---|---|
| id, name, email, company, address, city, postal_code, country, phone | — |

### 6. `{prefix}serviceflow_payment_schedule` — Échéanciers
| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT | PK |
| order_id | BIGINT | |
| type | VARCHAR(30) | upfront/installment |
| installment_no | TINYINT | Numéro de l'échéance |
| amount_ttc | DECIMAL(10,2) | Montant TTC de cette ligne |
| status | VARCHAR(20) | pending/paid |
| due_date | DATE | Date d'échéance |
| stripe_session_id | VARCHAR(255) | Session Stripe si lien de paiement envoyé |
| checkout_url | TEXT | URL Stripe Checkout |
| sent_at / paid_at / created_at | DATETIME | |

### 7. `{prefix}serviceflow_todos` — Tâches
| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT | PK |
| order_id | BIGINT | |
| label | VARCHAR(255) | Libellé de la tâche |
| source | VARCHAR(20) | pack/option (origine de création) |
| position | INT | Ordre d'affichage |
| is_completed | TINYINT | 0/1 |
| completed_at | DATETIME | |
| admin_note | TEXT | Note admin à la complétion |

---

## Cycle de vie d'une commande

```
pending ──► started ──► completed ──► revision ──► started (retouche)
  │                                       │
  │ (Stripe payé)                         └──► accepted (client valide)
  ▼
paid ──► started
```

**Qui peut faire quoi :**
- `started`, `completed` : admin uniquement (`manage_options`)
- `revision`, `accepted` : client uniquement (doit être le `client_id` de la commande)
- `completed` bloqué si des todos existent et ne sont pas tous à 100%
- Auto-complétion : quand le dernier todo est coché → statut passe automatiquement à `completed`

**Logique dans :** `ServiceFlow_Orders::transition_status()` → `can_transition()`

---

## Modes de paiement Stripe

| Mode | Description | Upfront | Solde |
|---|---|---|---|
| `single` | Paiement unique | 100% | — |
| `deposit` | Acompte | 50% | 50% à la livraison |
| `installments` | Versements | 40% | N mensualités égales |
| `monthly` | Abonnement | 1 mois | N-1 mois restants |

**Calculs dans :** `ServiceFlow_Payments::compute_total_ttc()`, `get_upfront_amount()`, `get_monthly_fee()`

---

## Flux Stripe Checkout complet

1. **Client clique "Commander"** → AJAX `serviceflow_stripe_checkout`
2. **`ServiceFlow_Stripe::ajax_create_checkout_session()`** :
   - Lit pack + options depuis `ServiceFlow_Options::get_packs()` / `get_options()`
   - Calcule `$upfront_amount` (TTC)
   - Construit les `line_items` :
     - Mode `single` → `build_line_items()` : items HT + ligne TVA séparée
     - Autres modes → item HT upfront + ligne TVA proportionnelle
   - Stocke dans `metadata` : `post_id`, `client_id`, `selected_pack`, `selected_indices`, `extra_pages`, `extra_page_price`, `maintenance_price`, `express_days`, `express_price`, `payment_mode`, `installments_count`, `full_total_ttc`
   - Retourne `checkout_url`
3. **Client complète le paiement** → Stripe envoie webhook
4. **Webhook** : `admin-ajax.php?action=serviceflow_stripe_webhook` → `handle_webhook_ajax()` lit `php://input`
5. **`process_completed_session()`** :
   - Idempotence : vérifie `stripe_session_id` déjà en DB
   - Reconstruit `$base_offer` et `$selected` depuis metadata
   - Appelle `ServiceFlow_Orders::create_order_paid()` avec tous les paramètres extra
   - Crée message récap dans le chat
   - Si mode non-single : crée échéancier + facture d'acompte
6. **Fallback** : si le webhook n'est pas encore arrivé quand l'utilisateur revient sur la page → `handle_return_redirect()` interroge l'API Stripe via `session_id` dans l'URL

**URL webhook :** `admin_url('admin-ajax.php?action=serviceflow_stripe_webhook')` (PAS REST API — retournait 404)

---

## Génération des factures

### Facture complète (mode `single`, via `create_invoice_for_order_status_change()`)
Déclenchée quand le statut passe à `accepted` ou `completed`.
Items construits depuis la commande :
1. `base_offer` → `{service_name} — {pack_name}` (service en gras dans le template)
2. `selected_options` → une ligne par option
3. `extra_pages` → libellé configurable + quantity = extra_pages
4. `maintenance_price` → libellé configurable + quantity 1
5. `express_days` → libellé configurable + quantity = express_days

### Facture partielle (`create_partial_invoice()`)
Créée lors du paiement Stripe. Un seul item résumé : "Acompte — [Service]" ou "Mensualité N — [Service]". Calcul inverse TTC → HT pour l'affichage.

### Libellés configurables (features avancées)
```php
ServiceFlow_Admin::get_extra_pages_label()   // option 'serviceflow_extra_pages_label', défaut 'Pages supplémentaires'
ServiceFlow_Admin::get_maintenance_label()   // option 'serviceflow_maintenance_label', défaut 'Maintenance mensuelle'
ServiceFlow_Admin::get_express_label()       // option 'serviceflow_express_label', défaut 'Livraison express'
```
Configurables dans Réglages → Options avancées (champ texte sous chaque checkbox).

---

## Freemius — Feature gating premium

```php
function serviceflow_is_premium(): bool {
    return ser_fs()->is__premium_only() && ser_fs()->can_use_premium_code();
}
```

| Fonctionnalité | Comportement free |
|---|---|
| Stripe Checkout | Bloqué (`init()` ne s'exécute pas) |
| Factures PDF | Bloqué |
| Todos | Bloqué (AJAX + `init()`) |
| Emails notifications | Bloqués |
| Menu notifs admin | Masqué |
| Services illimités | 1 seul en free |
| Options avancées (pages sup., maintenance, express) | Masquées en free |
| Badge PRO dashboard | Affiché si premium |

---

## Features avancées par service (métabox Options avancées)

Activées globalement dans **Réglages → Options avancées** (cases à cocher), prix défini **par service** dans la métabox post :

| Feature | Option globale | Meta prix | Getter label |
|---|---|---|---|
| Pages supplémentaires | `serviceflow_extra_pages` | `_serviceflow_extra_page_price` | `get_extra_pages_label()` |
| Maintenance mensuelle | `serviceflow_maintenance` | `_serviceflow_maintenance_price` | `get_maintenance_label()` |
| Livraison express | `serviceflow_express` | `_serviceflow_express_price` | `get_express_label()` |

---

## Todos — Règles métier

- Les todos sont créés automatiquement à la création d'une commande depuis les noms de packs/options
- **Blocage `completed`** : si des todos existent et que `ServiceFlow_Todos::get_progress()['percent'] < 100` → `can_transition()` retourne `false`
- **Auto-complétion** : quand le dernier todo est coché dans `ajax_toggle_todo()`, si `percent === 100` et statut = `started` → `transition_status($order_id, 'completed')` est appelé automatiquement
- **Message d'erreur explicite** : le blocage manuel donne : *"les tâches ne sont complétées qu'à X%"* (retourné avant `transition_status()` dans `ajax_order_transition()`)
- Si aucun todo n'existe → pas de blocage (la contrainte ne s'applique pas)

---

## Tous les endpoints AJAX

| Action | Classe | Accès |
|---|---|---|
| `serviceflow_send` | ServiceFlow_Ajax | Logged in |
| `serviceflow_poll` | ServiceFlow_Ajax | Logged in |
| `serviceflow_load` | ServiceFlow_Ajax | Logged in |
| `serviceflow_create_order` | ServiceFlow_Orders | Logged in |
| `serviceflow_order_transition` | ServiceFlow_Orders | Logged in |
| `serviceflow_get_clients` | ServiceFlow_Orders | Admin |
| `serviceflow_stripe_checkout` | ServiceFlow_Stripe | Logged in (premium) |
| `serviceflow_stripe_webhook` | ServiceFlow_Stripe | nopriv (Stripe) |
| `serviceflow_invoice_validate` | ServiceFlow_Invoices | Admin |
| `serviceflow_invoice_mark_paid` | ServiceFlow_Invoices | Admin |
| `serviceflow_invoice_cancel` | ServiceFlow_Invoices | Admin |
| `serviceflow_invoice_save` | ServiceFlow_Invoices | Admin |
| `serviceflow_invoice_update` | ServiceFlow_Invoices | Admin |
| `serviceflow_invoice_set_status` | ServiceFlow_Invoices | Admin |
| `serviceflow_save_ext_client` | ServiceFlow_Invoices | Admin |
| `serviceflow_delete_ext_client` | ServiceFlow_Invoices | Admin |
| `serviceflow_save_invoice_settings` | ServiceFlow_Invoices | Admin |
| `serviceflow_view_invoice` | ServiceFlow_Invoices | Logged in |
| `serviceflow_notif_count` | ServiceFlow_Notifications | Logged in |
| `serviceflow_notif_list` | ServiceFlow_Notifications | Logged in |
| `serviceflow_notif_read_all` | ServiceFlow_Notifications | Logged in |
| `serviceflow_preview_email` | ServiceFlow_Notifications | Admin (premium) |
| `serviceflow_send_payment_link` | ServiceFlow_Payments | Admin |
| `serviceflow_mark_payment_paid` | ServiceFlow_Payments | Admin |
| `serviceflow_rebuild_schedule` | ServiceFlow_Payments | Admin |
| `serviceflow_schedule_check` | ServiceFlow_Payments | nopriv + logged |
| `serviceflow_toggle_todo` | ServiceFlow_Todos | Admin (premium) |
| `serviceflow_get_todos` | ServiceFlow_Todos | Logged in |
| `serviceflow_update_profile` | ServiceFlow_Account | Logged in |
| `serviceflow_upload_avatar` | ServiceFlow_Account | Logged in |

---

## Shortcodes

| Shortcode | Classe | Description |
|---|---|---|
| `[serviceflow_options]` | ServiceFlow_Front | Affiche les packs/options d'un service (frontend) |
| `[serviceflow_my_account]` | ServiceFlow_Account | Page compte client complète |

---

## Règles de code — WordPress.org

### Output escaping (obligatoire)
```php
echo esc_html( $var );         // texte brut
echo esc_attr( $var );         // attribut HTML
echo esc_url( $url );          // URLs
echo absint( $int );           // entiers positifs
echo wp_kses_post( $html );    // HTML riche (Elementor, etc.)
```

### Input sanitization (obligatoire)
```php
sanitize_text_field( wp_unslash( $_POST['field'] ) )  // texte
sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) // textarea
absint( $_POST['id'] )                                 // entier
floatval( wp_unslash( $_POST['price'] ) )             // décimal
// Mots de passe : NE PAS sanitize → phpcs:ignore InputNotSanitized
```

### DB queries
```php
// Noms de table interpolés → toujours ignorer avec commentaire :
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
```

### i18n
```php
/* translators: %1$s: description, %2$d: nombre */
sprintf( __( 'Texte %1$s et %2$d', 'serviceflow' ), $var1, $var2 );
// Placeholders ordonnés obligatoires (%1$s pas %s) quand plusieurs args
// wp_die() → utiliser esc_html__() pas __()
```

---

## Process de build et déploiement

```powershell
# Rebuild le zip (depuis le dossier du plugin)
powershell -ExecutionPolicy Bypass -File build.ps1

# Upload vers GitHub release v1.0.0 (remplace le fichier existant)
"/c/Program Files/GitHub CLI/gh.exe" release upload v1.0.0 dist/serviceflow-1.0.0.zip --clobber
```

**Après chaque modification :** rebuild + upload.

---

## Réglages clés (options WP)

| Option | Description |
|---|---|
| `serviceflow_db_version` | Version DB installée (comparée à `SERVICEFLOW_VERSION`) |
| `serviceflow_orders_extra_cols` | Migration ponctuelle colonnes extra dans orders |
| `serviceflow_client_id_migrated` | Migration client_id messages |
| `serviceflow_post_type` | Custom Post Type utilisé (défaut : `annonce`) |
| `serviceflow_color` | Couleur principale du chat |
| `serviceflow_position` | Position bouton chat (left/right) |
| `serviceflow_extra_pages` | Feature pages sup. activée (1/0) |
| `serviceflow_extra_pages_label` | Libellé personnalisé facture |
| `serviceflow_maintenance` | Feature maintenance activée (1/0) |
| `serviceflow_maintenance_label` | Libellé personnalisé facture |
| `serviceflow_express` | Feature express activée (1/0) |
| `serviceflow_express_label` | Libellé personnalisé facture |

---

## Meta clés par post (service CPT)

| Meta key | Type | Description |
|---|---|---|
| `_serviceflow_extra_page_price` | float | Prix HT par page supplémentaire |
| `_serviceflow_maintenance_price` | float | Prix HT maintenance mensuelle |
| `_serviceflow_express_price` | float | Prix HT par jour express |
| `_serviceflow_payment_mode` | string | Mode paiement par défaut du service |
| `_serviceflow_installments_count` | int | Nombre de mensualités |

---

## Points d'attention critiques

1. **Ne jamais bumper `SERVICEFLOW_VERSION`** — version figée à `1.0.0` pour la release publique. Toute migration DB utilise une option dédiée.

2. **Webhook Stripe via admin-ajax** — NE PAS passer par REST API (retourne 404 sur certains hébergements). URL : `admin_url('admin-ajax.php?action=serviceflow_stripe_webhook')`.

3. **TVA sur Stripe Checkout** — Les prix sont envoyés HT + ligne TVA séparée (`build_line_items()`). Ne pas multiplier les prix par le facteur TVA.

4. **`selected_options` ne contient PAS les extras** — `extra_pages`, `maintenance`, `express` sont des colonnes séparées dans `serviceflow_orders`. Les lire depuis `$order->extra_pages` etc., pas depuis `selected_options`.

5. **Facture item `service_name`** — Le champ `service_name` dans les items est affiché en gras dans le template HTML de la facture (`<strong>`). Ne pas le mettre dans `description`.

6. **Auto-complétion todos** — `ajax_toggle_todo()` appelle `transition_status()` directement. Le check `can_transition()` utilise `$order->id` (pas `$order_id` — variable non définie dans ce scope).

7. **`lib/` et `vendor/`** — Exclus de PHPCS via `phpcs.xml`. Ne jamais les modifier.
