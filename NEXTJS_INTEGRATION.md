# Intégration Next.js — API CVBoost AI

Ce guide décrit le contrat actuellement implémenté par l’API Laravel et les appels à effectuer depuis Next.js.

## 1. Configuration

En local, l’API est disponible à `http://127.0.0.1:8000/api/v1`. Le serveur Laravel doit être démarré avec `php artisan serve`.

Dans `.env.local` de Next.js :

```dotenv
API_BASE_URL=http://127.0.0.1:8000/api/v1
```

Utilisez `API_BASE_URL` côté serveur, sans préfixe `NEXT_PUBLIC_`. En production, renseignez l’URL HTTPS de l’API. Laravel autorise par défaut `http://localhost:3000` ; pour une autre origine, configurez `CORS_ALLOWED_ORIGINS` côté Laravel (origines séparées par des virgules).

Toutes les routes ci-dessous sont relatives à `/api/v1`. Les exemples de corps utilisent JSON, sauf l’import de fichier (`multipart/form-data`). Les routes privées nécessitent un jeton Laravel Sanctum :

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

Le jeton est retourné par l’inscription ou la connexion. Il s’agit d’un bearer token, pas d’une session cookie Sanctum. Ne mettez jamais les clés Gemini, Groq ou SasPay dans Next.js. Pour le jeton utilisateur, privilégiez un stockage serveur dans un cookie `HttpOnly`, `Secure` en production et `SameSite`; évitez `localStorage` lorsque c’est possible.

## 2. Client TypeScript minimal

Exemple pour les appels serveur Next.js (Server Components, Server Actions ou Route Handlers). À adapter au mécanisme choisi pour lire le jeton :

```ts
const API_BASE_URL = process.env.API_BASE_URL;

export async function apiFetch<T>(
  path: string,
  options: RequestInit = {},
  token?: string,
): Promise<T> {
  if (!API_BASE_URL) throw new Error("API_BASE_URL n'est pas configurée.");

  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  if (token) headers.set("Authorization", `Bearer ${token}`);

  const isFormData = options.body instanceof FormData;
  if (options.body && !isFormData && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers,
    cache: "no-store",
  });

  const body = await response.json().catch(() => null);
  if (!response.ok) {
    const error = new Error(body?.message ?? `Erreur API (${response.status})`);
    Object.assign(error, { status: response.status, details: body?.errors });
    throw error;
  }
  return body as T;
}
```

Exemple d’appel :

```ts
const result = await apiFetch<{ user: User; token: string }>(
  "/auth/login",
  {
    method: "POST",
    body: JSON.stringify({ email, password }),
  },
);
```

Pour les appels côté navigateur, préférez des Route Handlers Next.js comme BFF : ils peuvent récupérer le bearer token depuis un cookie HttpOnly et appeler Laravel côté serveur. Ne transmettez jamais le secret du prestataire de paiement ni les clés IA au navigateur.

## 3. Référence des routes

| Méthode | URL relative | Auth | Fonction |
|---|---|---:|---|
| `POST` | `/auth/register` | Non | Créer un compte et recevoir un jeton |
| `POST` | `/auth/login` | Non | Se connecter et recevoir un jeton |
| `POST` | `/auth/logout` | Oui | Révoquer le jeton courant |
| `POST` | `/auth/forgot-password` | Non | Envoyer un lien de réinitialisation |
| `GET` | `/me` | Oui | Lire le compte connecté |
| `PATCH` | `/me` | Oui | Modifier le compte |
| `DELETE` | `/me` | Oui | Supprimer le compte et ses données associées |
| `GET` | `/cvs` | Oui | Lister les CV (20 par page) |
| `POST` | `/cvs` | Oui | Créer un CV |
| `GET` | `/cvs/{cv}` | Oui | Lire un CV |
| `PUT`, `PATCH` | `/cvs/{cv}` | Oui | Modifier un CV |
| `DELETE` | `/cvs/{cv}` | Oui | Supprimer un CV |
| `POST` | `/cvs/{cv}/duplicate` | Oui | Dupliquer un CV |
| `POST` | `/cvs/{cv}/export` | Oui | Consommer une autorisation d’export PDF/TXT |
| `POST`, `DELETE` | `/cvs/{cv}/modification-session` | Oui | Ouvrir ou fermer une session de modification incluse |
| `POST` | `/cv/import` | Oui | Extraire le texte d’un fichier PDF, DOCX, TXT ou MD |
| `POST` | `/cv/analyze` | Oui | Calculer le score ATS et l’analyse |
| `POST` | `/cv/optimize` | Oui | Obtenir une proposition générée par IA |
| `POST` | `/payments` | Oui | Créer une demande de paiement d’export |
| `GET` | `/payments` | Oui | Lister l’historique des paiements (20 par page) |
| `GET` | `/payments/{payment}` | Oui | Lire et, si nécessaire, revérifier un paiement en attente |
| `POST` | `/payments/webhook/{provider}` | Signature | Recevoir le webhook du prestataire, côté serveur uniquement |
| `GET` | `/cvs/{cv}/export-permission` | Oui | Vérifier le droit et les quotas d’export/modification |
| `GET` | `/me/preferences` | Oui | Lire les préférences |
| `PATCH` | `/me/preferences` | Oui | Enregistrer les préférences |

Un identifiant de CV ou paiement appartenant à un autre compte est masqué et renvoie `404`. Les routes d’authentification sont limitées : 10 requêtes/minute pour inscription/connexion, 5/minute pour le lien de réinitialisation.

## 4. Authentification et compte

### Inscription

`POST /auth/register`

```json
{
  "name": "Jean Dupont",
  "email": "jean@example.com",
  "password": "mot-de-passe",
  "password_confirmation": "mot-de-passe"
}
```

Réponse `201` :

```json
{
  "message": "Compte créé.",
  "user": { "id": 1, "name": "Jean Dupont", "email": "jean@example.com" },
  "token": "1|..."
}
```

Le mot de passe doit comporter au moins 8 caractères. L’email doit être unique et validé par le contrôle RFC/DNS.

### Connexion

`POST /auth/login`

```json
{ "email": "jean@example.com", "password": "mot-de-passe" }
```

Réponse `200` : `{ "user": { ... }, "token": "1|..." }`. Un échec de connexion renvoie `422` avec une erreur associée à `email`.

### Déconnexion

`POST /auth/logout` avec le bearer token. Réponse : `{ "message": "Déconnexion réussie." }`. Seul le jeton courant est révoqué ; le frontend doit aussi supprimer son cookie/local token.

### Mot de passe oublié

`POST /auth/forgot-password`

```json
{ "email": "jean@example.com" }
```

Réponse : `{ "message": "..." }`. La route déclenche l’envoi Laravel du lien de réinitialisation. **Aucune route API de soumission du nouveau mot de passe n’est déclarée dans cette API** : le flux de réinitialisation complet nécessite une route/interface additionnelle et une configuration mail Laravel.

### Profil

- `GET /me` renvoie `{ "user": { "id", "name", "email", ... } }`.
- `PATCH /me` accepte partiellement `name`, `email`, `password`. Pour changer le mot de passe, envoyer `password_confirmation` également. Le résultat est `{ "user": { ... } }`.
- `DELETE /me` supprime le compte et ses données liées ; réponse `{ "message": "Compte et données associées supprimés." }`.

## 5. CV

Le champ `content` est un objet libre (`JSON`), à structurer selon les types de l’application Next.js. Exemple de forme acceptée :

```json
{
  "contact": { "name": "Jean Dupont", "email": "jean@example.com", "phone": "+237600000000" },
  "profile": "Développeur motivé...",
  "experiences": [{ "title": "Développeur", "company": "Entreprise", "description": "..." }],
  "education": [{ "degree": "BTS", "school": "Institut", "year": "2026" }],
  "skills": ["PHP", "Laravel", "PostgreSQL"],
  "languages": [{ "name": "Français", "level": "Courant" }],
  "projects": [],
  "certifications": []
}
```

`POST /cvs` requiert `title` (chaîne, max. 180), `template` (chaîne, max. 80) et `content` (objet). `color` (max. 30), `job_offer`, `ats_score` (0–100) et `ats_analysis` sont facultatifs.

```json
{
  "title": "CV Développeur",
  "template": "modern",
  "color": "#2563eb",
  "content": { "profile": "...", "skills": ["Laravel"] },
  "job_offer": "Offre optionnelle"
}
```

Création et duplication renvoient `201` avec `{ "cv": { ... } }`. Lecture et modification renvoient `{ "cv": { ... } }`. Suppression : `{ "message": "CV supprimé." }`.

`GET /cvs` renvoie la pagination Laravel, avec notamment `data`, `current_page`, `last_page`, `per_page`, `total` et les liens de navigation. Taille de page fixe : 20. Les paramètres `page` et navigation par les liens de pagination peuvent être utilisés.

### Importer un fichier

`POST /cv/import` avec un `FormData` contenant le champ `file`. Formats acceptés : PDF, DOCX, TXT, MD ; taille maximale par défaut : 10 Mo.

```ts
const form = new FormData();
form.append("file", file);
const imported = await apiFetch<{
  filename: string;
  text: string;
  characters: number;
}>("/cv/import", { method: "POST", body: form }, token);
```

Ne définissez pas manuellement `Content-Type` pour le multipart : le navigateur doit ajouter la boundary. Réponse : `filename`, texte extrait (tronqué à 100 000 caractères) et nombre de caractères extraits. Les PDF scannés ne sont pas OCRisés et peuvent répondre `422` si aucun texte n’est lisible. Dans le parcours Next.js, le texte extrait est présenté dans un champ modifiable avant l’analyse ; il est conservé dans `content.source_text` du CV importé afin que l’analyse ATS exploite tout le texte original, même si la conversion des rubriques structurées n’en reconnaît qu’une partie.

### Analyse ATS

`POST /cv/analyze` accepte le contenu direct, ou un CV existant appartenant à l’utilisateur :

```json
{ "content": { "profile": "...", "experiences": [], "skills": ["PHP"] }, "job_offer": "Offre..." }
```

ou :

```json
{ "cv_id": 12, "job_offer": "Offre..." }
```

`content` est requis si `cv_id` n’est pas fourni ; `job_offer` est facultatif (max. 30 000 caractères). En cas de `cv_id`, le score, l’analyse et l’offre sont enregistrés sur le CV.

Réponse :

```json
{
  "score": 72,
  "strengths": ["La rubrique profile est renseignée."],
  "suggestions": ["Ajoutez une rubrique compétences."],
  "matched_keywords": ["php"],
  "missing_keywords": ["laravel"],
  "sections": {
    "contact": true,
    "profile": true,
    "experiences": true,
    "education": false,
    "skills": false
  }
}
```

Le score est indicatif et calculé localement par l’API.

### Optimisation IA

`POST /cv/optimize`

```json
{
  "content": { "profile": "...", "experiences": [] },
  "job_offer": "Offre (max. 30 000 caractères)",
  "section": "experiences"
}
```

`section` est facultatif. Réponse : `{ "proposal": { "professional_profile": "...", "experience_titles": ["Intitulé amélioré par expérience, dans le même ordre"], "experience_bullets": ["Une reformulation par puce existante, dans l'ordre des expériences et des puces"], "analysis": "..." } }`. L’utilisateur peut modifier ou accepter séparément le profil, les intitulés de poste et chaque mission suggérés. En cas de service IA indisponible ou non configuré, l’API renvoie `503`. Les propositions sont à valider avant de remplacer les données du CV.

## 6. Paiements et droits d’export

### Créer puis suivre un paiement

`POST /payments`

```json
{ "cv_id": 12 }
```

`phone_number` et `operator` sont facultatifs. Le checkout SasPay laisse le client choisir le moyen et les informations de paiement sur sa page hébergée. Pour le Cameroun, le backend utilise le pays `CM` et la devise `XAF` (réseaux documentés : `mtn_cm` et `orange_cm`). Le montant et la devise sont déterminés par le backend ; le frontend ne doit pas les imposer comme preuve de paiement. La demande minimale du frontend est donc `{ "cv_id": 12 }`.

Réponse `201` :

```json
{
  "payment": {
    "id": 1,
    "order_id": "CVB-...",
    "provider": "saspay",
    "amount": 500,
    "currency": "XAF",
    "status": "pending",
    "payment_link": "..."
  },
  "payment_link": "...",
    "message": "Session de paiement créée. Le statut sera vérifié auprès de SasPay au retour du client."
}
```

Redirigez l’utilisateur vers `payment_link` (`checkout_url` SasPay). Après un paiement réussi, SasPay renvoie le navigateur vers une URL Laravel signée, qui vérifie la session avec `GET /checkout-sessions/{id}/status/`, revérifie la transaction avec `GET /payments/{transaction_id}/verify/`, puis redirige vers `/resultat/{cvId}?payment_status=...`. `GET /payments/{paymentId}` revérifie également côté serveur les paiements `pending`. Les statuts locaux sont `pending`, `completed` et `failed`. `GET /payments` renvoie l’historique paginé (20/page).

### Vérifier le droit d’export

`GET /cvs/{cvId}/export-permission` :

```json
{
  "allowed": true,
  "modification_allowed": true,
  "paid": true,
  "paid_at": "2026-01-01T12:00:00.000000Z",
  "prints_remaining": 2,
  "modifications_remaining": 1,
  "price": 500,
  "currency": "XAF"
}
```

Chaque paiement réussi ouvre un quota d’au plus deux exports PDF/TXT et une session de modification. Ces quotas sont indépendants : épuiser le quota d’export n’empêche pas d’utiliser la session de modification restante, et inversement. Un nouveau paiement est requis pour l’action dont le quota est épuisé et réinitialise les deux quotas. Les CV dupliqués ne reprennent jamais le paiement ni les quotas de leur original.

Avant chaque export, le frontend appelle `POST /cvs/{cvId}/export`. Le backend vérifie puis consomme atomiquement une impression ; un simple appel de lecture au droit d’export ne consomme rien. Le frontend ne doit déclencher l’impression ou le téléchargement que si cet appel réussit.

Lorsqu’un CV a été payé, appeler `POST /cvs/{cvId}/modification-session` avant de permettre des changements. Si `modification_allowed` est faux, le frontend peut demander un nouveau paiement. La réponse fournit un `session_id` valable deux heures. Transmettre cette valeur dans `modification_session` à chaque `PUT/PATCH /cvs/{cvId}`. Les sauvegardes de la même session comptent comme une seule modification ; la première sauvegarde de contenu consomme le quota. Fermer explicitement la session avec `DELETE /cvs/{cvId}/modification-session` et `{ "session_id": "..." }`. Sans session valide, Laravel refuse les changements de titre, modèle, couleur ou contenu d’un CV payé. Les brouillons non payés restent modifiables.

La confirmation affichée par la page de paiement ou le retour navigateur ne suffit pas à autoriser l’export ; seul le backend valide le paiement auprès de SasPay.

### Retour de checkout

Le parcours actuel n’utilise pas de webhook. `GET /payments/return/{payment}` est une route Laravel signée appelée par la redirection SasPay. En local, `APP_URL=http://127.0.0.1:8000` et `FRONTEND_URL=http://localhost:3000` permettent le retour vers le navigateur du même poste ; aucun tunnel public n’est requis pour ce retour navigateur. Les webhooks ne doivent pas être simulés depuis Next.js.

## 7. Préférences

`GET /me/preferences` renvoie `{ "preferences": { "language": "fr", "theme": "light", "notifications": {} } }` (la préférence est créée avec ces valeurs par défaut au premier accès).

`PATCH /me/preferences` accepte une ou plusieurs propriétés :

```json
{ "language": "fr", "theme": "system", "notifications": { "product_updates": true } }
```

Valeurs autorisées : `language` = `fr` ou `en` ; `theme` = `light`, `dark` ou `system` ; `notifications` = objet JSON libre.

## 8. Erreurs et recommandations frontend

Les erreurs de validation Laravel utilisent généralement le statut `422` :

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["..."],
    "password": ["..."]
  }
}
```

Traitez au minimum :

- `401` : jeton absent ou invalide ; supprimer le jeton et rediriger vers la connexion.
- `404` : ressource absente ou appartenant à un autre compte.
- `402` : quota d’export ou de modification épuisé ; un nouveau paiement est requis.
- `422` : afficher les messages associés aux champs via `errors`.
- `429` : limite de requêtes dépassée ; proposer une nouvelle tentative plus tard.
- `502` : création du paiement impossible côté prestataire.
- `503` : service IA temporairement indisponible.

Ne mettez pas en cache les réponses privées (`cache: "no-store"`). Gérez les états de chargement, les erreurs réseau et les réponses vides. En production, utilisez HTTPS, limitez CORS au domaine Next.js, configurez le mailer Laravel et la clé secrète SasPay, et vérifiez les paiements exclusivement auprès du backend.
