# CVBoost AI — API Laravel 12

API REST pour le frontend CVBoost AI (Next.js 14). Compatible PHP 8.2+ et PostgreSQL. Les clés Gemini, Groq et SasPay restent exclusivement côté serveur.

## 1. Installation Windows (XAMPP / PowerShell)

Prérequis : PHP 8.2+, Composer 2, PostgreSQL, extensions PHP `pdo_pgsql`, `pgsql`, `mbstring`, `fileinfo`, `openssl`, `curl`, `zip`, `xml`.

```powershell
cd CVBoost-AI-Laravel-12
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Créez une base PostgreSQL nommée `cvboost_ai`, puis complétez `.env` :
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (le cache et les sessions utilisent des fichiers par défaut)
- `FRONTEND_URL` et `CORS_ALLOWED_ORIGINS`
- `GEMINI_API_KEY` et/ou `GROQ_API_KEY`
- `SASPAY_SECRET_KEY` depuis SasPay → Développeur. Gardez-la uniquement côté serveur.

Puis :

```powershell
php artisan migrate
php artisan serve
```

API : `http://127.0.0.1:8000/api/v1` ; santé : `http://127.0.0.1:8000/up`.

## 2. Routes principales

Toutes les routes privées utilisent `Authorization: Bearer VOTRE_JETON`.

| Méthode | Route | Auth | Fonction |
|---|---|---:|---|
| POST | `/auth/register` | non | Inscription (`name,email,password,password_confirmation`) |
| POST | `/auth/login` | non | Connexion |
| POST | `/auth/logout` | oui | Révoquer le jeton courant |
| POST | `/auth/forgot-password` | non | Demande de réinitialisation |
| GET/PATCH/DELETE | `/me` | oui | Profil / suppression |
| GET/POST | `/cvs` | oui | Lister / créer |
| GET/PATCH/DELETE | `/cvs/{id}` | oui | Lire / modifier / supprimer |
| POST | `/cvs/{id}/duplicate` | oui | Dupliquer |
| POST | `/cv/import` | oui | Import PDF, DOCX, TXT, MD (10 Mo max) |
| POST | `/cv/analyze` | oui | Score ATS et mots-clés |
| POST | `/cv/optimize` | oui | Propositions Gemini/Groq |
| POST | `/payments` | oui | Démarrer paiement export |
| GET | `/payments/{id}` | oui | Consulter et revérifier |
| GET | `/payments` | oui | Historique |
| GET | `/payments/return/{payment}` | lien signé | Retour SasPay, vérification serveur puis redirection frontend |
| GET | `/cvs/{id}/export-permission` | oui | Droit PDF/TXT |
| GET/PATCH | `/me/preferences` | oui | Langue, thème, notifications |

## 3. Structure du CV

Le champ `content` est un objet JSON libre afin de préserver le contrat frontend `types.ts` : coordonnées/contact, profil, expériences, formations, compétences, langues, projets et certifications. Exemple :

```json
{
  "contact": {"name":"Jean Dupont","email":"jean@example.com","phone":"+237600000000"},
  "profile":"Développeur motivé...",
  "experiences":[{"title":"Développeur","company":"Entreprise","description":"..."}],
  "education":[{"degree":"BTS","school":"Institut","year":"2026"}],
  "skills":["PHP","Laravel","PostgreSQL"],
  "languages":[{"name":"Français","level":"Courant"}],
  "projects":[],
  "certifications":[]
}
```

Création CV : `POST /cvs` avec `title`, `template`, `color`, `content`, `job_offer` (optionnel). Chaque requête utilise la relation de l'utilisateur connecté ; un identifiant appartenant à un autre compte retourne 404.

## 4. Paiement SasPay — checkout hébergé

Le prix d'export est `500 XAF` par CV par défaut, configurable par `CV_EXPORT_PRICE` et `CV_EXPORT_CURRENCY`. Le frontend appelle `POST /payments` avec `cv_id`, `phone_number` et `operator`. Laravel crée une session via `POST https://api.saspay.me/api/v1/checkout-sessions/`, puis retourne `payment_link` (`checkout_url`) au frontend pour rediriger le client vers SasPay.

Au retour du checkout réussi, SasPay redirige vers une URL Laravel signée. Le backend consulte `GET /checkout-sessions/{id}/status/`, puis revérifie la transaction avec `GET /payments/{transaction_id}/verify/`. Le droit d'export n'est accordé que si le statut vérifié, le montant et la devise correspondent. Les retours d'échec ou d'annulation ne redirigent pas automatiquement selon la documentation SasPay ; le client peut fermer le checkout et relancer le paiement.

Pour le Cameroun, SasPay documente le pays `CM`, la devise `XAF` et les réseaux actifs `mtn_cm` et `orange_cm`. La page hébergée présente les moyens disponibles. En local, `APP_URL` doit pointer vers Laravel (par défaut `http://127.0.0.1:8000`) et `FRONTEND_URL` vers Next.js (par défaut `http://localhost:3000`). Aucun webhook n'est utilisé dans ce parcours de retour navigateur.

Ne mettez jamais `SASPAY_SECRET_KEY` dans Next.js ni dans le navigateur. Configurez la clé depuis SasPay → Développeur et videz le cache Laravel après toute modification de `.env`.

## 5. IA et ATS

- Gemini prioritaire ; Groq utilisé en secours si Gemini est absent ou échoue.
- `POST /cv/optimize` reçoit `content`, `job_offer`, `section` optionnelle.
- Le score ATS local fournit score, points forts, suggestions, mots-clés présents/manquants. Ce score est indicatif.
- Les analyses sont sans compteur/limite côté API.
- Sans clé IA, l'optimisation renvoie 503 ; le frontend peut conserver son moteur local.

## 6. Import et limites

PDF textuels via PDFParser, DOCX via PHPWord, TXT/MD en texte brut. Maximum 10 Mo par défaut. Les PDF scannés ne sont pas OCRisés. Le fichier est traité temporairement et n'est pas conservé.

## 7. À finaliser avant production

1. Configurer mailer SMTP pour les liens de réinitialisation et vérifier la réception.
2. Confirmer auprès de SasPay l’activation du checkout marchand et les réseaux disponibles pour le Cameroun.
3. Tester le retour signé et la vérification serveur avec une transaction de test avant la production.
4. Définir `APP_DEBUG=false`, `APP_ENV=production`, HTTPS, CORS limité au domaine réel et sauvegardes PostgreSQL.
5. Ajouter vérification d'adresse e-mail et tests d'intégration spécifiques à votre compte marchand.
6. Générer les exports PDF/TXT côté frontend uniquement après autorisation backend.

Ce ZIP contient le code source applicatif Laravel et les migrations. `composer install` télécharge les dépendances Laravel : un dossier `vendor/` n'est volontairement pas inclus.
