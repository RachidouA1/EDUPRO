# CLAUDE.md — E-EDU PRO

## Propriétaire du projet
**Rachidou** — Développeur principal du projet E-EDU PRO.

---

## Présentation du projet

**E-EDU PRO** est une application de gestion scolaire web destinée aux **écoles professionnelles**, située à **Tahoua**, région de Tahoua, **Niger**. Elle couvre la gestion des étudiants, des enseignants, des notes, des bulletins, de la comptabilité et de l'administration.

- Base URL locale : `http://localhost/EDUPRO`
- Point d'entrée : `index.php` (login) → `dashboard.php`
- Installation BDD : `install/setup.php`

---

## Stack technique

### Backend
- **Langage** : PHP (procédural, sans framework)
- **Base de données** : MySQL — base `edupro`
- **Accès BDD** : PDO (configuré dans `config/database.php`)
- **Auth** : Sessions PHP + hachage de mots de passe + tokens CSRF

### Frontend
- **HTML5** + PHP embarqué (templates côté serveur)
- **Bootstrap 5.3.2** — composants UI et design responsive
- **Font Awesome 6.4.2** — icônes
- **Google Fonts** (Inter)
- **JavaScript vanilla** + Bootstrap JS bundle
- **AJAX** pour les dropdowns dépendants (ex. filière → niveau)

### Serveur / Environnement
- **XAMPP** (Apache + MySQL + PHP) sur Windows
- **Fuseau horaire** : Africa/Niamey
- **Encodage** : UTF-8 MB4

---

## Outils de développement

| Outil | Usage |
|-------|-------|
| **XAMPP** | Serveur local Apache + MySQL |
| **VS Code** | Éditeur principal (`.vscode/` présent) |
| **PhpStorm** | IDE PHP alternatif (`.idea/` présent) |
| **Claude Code** | Assistant IA de développement (`.claude/` présent) |
| **Git** | Contrôle de version |
| **MySQL Workbench / phpMyAdmin** | Administration de la base de données |

---

## Structure du projet

```
E-EDU PRO/
├── api/              # Endpoints AJAX (niveaux, semestres, recherche étudiants)
├── assets/           # CSS (style.css) et JS (app.js) personnalisés
├── config/           # config.php (constantes, session) + database.php (PDO)
├── includes/         # Composants partagés : auth, functions, header, footer, sidebar
├── install/          # Scripts d'installation et migration BDD
├── modules/
│   ├── etudiants/    # Gestion des étudiants + paiements
│   ├── enseignants/  # Gestion des enseignants + salaires
│   ├── pedagogique/  # Notes, bulletins, matières
│   ├── comptabilite/ # Recettes, dépenses, rapports, reçus
│   └── administration/ # Utilisateurs, filières, années académiques
├── index.php         # Page de connexion
├── dashboard.php     # Tableau de bord principal
└── logout.php        # Déconnexion
```

---

## Rôles utilisateurs

- `admin` — accès total
- `directeur` — supervision globale
- `comptable` — module comptabilité
- `scolarite` — gestion pédagogique et étudiants
- `enseignant` — saisie des notes
- `etudiant` — consultation des notes et bulletins

---

## Conventions de code

- Validation et sanitisation des entrées à chaque point d'entrée (formulaires, paramètres GET/POST)
- Protection CSRF via tokens de session
- Utiliser PDO avec requêtes préparées — jamais de concaténation directe dans les requêtes SQL
- Messages flash via le système de session pour le feedback utilisateur
- Vérification des rôles avec `requireRole()` en tête de chaque module protégé

---

## Base de données

- **Nom** : `edupro`
- **Tables principales** : `users`, `etudiants`, `enseignants`, `filieres`, `niveaux`, `semestres`, `annees_academiques`, `notes`, `paiements_etudiants`, `recettes`, `depenses`, `matieres`, `bulletins`
