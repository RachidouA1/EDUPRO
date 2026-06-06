CREATE DATABASE IF NOT EXISTS `edupro` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `edupro`;

-- ════════════════════════════════════════════════════════════════════
-- ÉCOLES  (hub central — créée en premier car les autres en dépendent)
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS ecoles (
    id                     INT PRIMARY KEY AUTO_INCREMENT,
    code                   VARCHAR(20)  NOT NULL UNIQUE,
    nom                    VARCHAR(255) NOT NULL,
    slogan                 VARCHAR(255) DEFAULT NULL,
    adresse                TEXT         DEFAULT NULL,
    ville                  VARCHAR(100) DEFAULT NULL,
    pays                   VARCHAR(100) DEFAULT 'Niger',
    telephone              VARCHAR(50)  DEFAULT NULL,
    email                  VARCHAR(150) DEFAULT NULL,
    logo_path              VARCHAR(255) DEFAULT NULL,
    cachet_dg_path         VARCHAR(255) DEFAULT NULL,
    theme_couleur_primaire VARCHAR(7)   DEFAULT '#1a73e8',
    theme_couleur_sidebar  VARCHAR(7)   DEFAULT '#0f2d5c',
    actif                  TINYINT(1)   DEFAULT 1,
    created_at             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- USERS
-- superadmin : ecole_id = NULL (accès global)
-- tous les autres rôles : ecole_id = ID de leur école
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS users (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    nom          VARCHAR(100) NOT NULL,
    prenom       VARCHAR(100) NOT NULL,
    email        VARCHAR(150) UNIQUE NOT NULL,
    password     VARCHAR(255) NOT NULL,
    role         ENUM('superadmin','admin','directeur','scolarite','enseignant','comptable','etudiant','coordinateur','assistante') NOT NULL DEFAULT 'etudiant',
    ecole_id     INT          DEFAULT NULL,
    reference_id INT          NULL,
    actif        TINYINT(1)   DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ecole_id (ecole_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- FILIÈRES
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS filieres (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id     INT          NOT NULL,
    code         VARCHAR(20)  NOT NULL,
    nom          VARCHAR(200) NOT NULL,
    description  TEXT,
    duree_annees INT          NOT NULL DEFAULT 3,
    actif        TINYINT(1)   DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_code_ecole (code, ecole_id),
    INDEX idx_ecole_id (ecole_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- NIVEAUX
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS niveaux (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id   INT NOT NULL,
    filiere_id INT NOT NULL,
    nom        VARCHAR(50) NOT NULL,
    ordre      INT         NOT NULL DEFAULT 1,
    INDEX idx_ecole_id (ecole_id),
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- ANNÉES ACADÉMIQUES
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS annees_academiques (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id   INT         NOT NULL,
    libelle    VARCHAR(20) NOT NULL,
    date_debut DATE,
    date_fin   DATE,
    actif      TINYINT(1)  DEFAULT 0,
    created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ecole_id (ecole_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- SEMESTRES
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS semestres (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    annee_id   INT         NOT NULL,
    nom        VARCHAR(50) NOT NULL,
    date_debut DATE,
    date_fin   DATE,
    actif      TINYINT(1)  DEFAULT 0,
    FOREIGN KEY (annee_id) REFERENCES annees_academiques(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- ENSEIGNANTS
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS enseignants (
    id               INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id         INT         NOT NULL,
    matricule        VARCHAR(20) NOT NULL,
    nom              VARCHAR(100) NOT NULL,
    prenom           VARCHAR(100) NOT NULL,
    sexe             ENUM('M','F') NOT NULL,
    date_naissance   DATE,
    telephone        VARCHAR(20),
    email            VARCHAR(150),
    adresse          TEXT,
    specialite       VARCHAR(200),
    type_contrat     ENUM('permanent','vacataire') DEFAULT 'permanent',
    salaire_base     DECIMAL(10,2) DEFAULT 0,
    date_recrutement DATE,
    actif            TINYINT(1)  DEFAULT 1,
    created_at       TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matricule_ecole (matricule, ecole_id),
    INDEX idx_ecole_id (ecole_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- ÉTUDIANTS
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS etudiants (
    id               INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id         INT          NOT NULL,
    matricule        VARCHAR(20)  NOT NULL,
    nom              VARCHAR(100) NOT NULL,
    prenom           VARCHAR(100) NOT NULL,
    sexe             ENUM('M','F') NOT NULL,
    date_naissance   DATE,
    lieu_naissance   VARCHAR(100),
    telephone        VARCHAR(20),
    email            VARCHAR(150),
    adresse          TEXT,
    nom_tuteur       VARCHAR(200),
    telephone_tuteur VARCHAR(20),
    filiere_id       INT,
    niveau_id        INT,
    annee_id         INT,
    statut           ENUM('actif','transfere','exclu','diplome') DEFAULT 'actif',
    photo            VARCHAR(255),
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matricule_ecole (matricule, ecole_id),
    INDEX idx_ecole_id (ecole_id),
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE SET NULL,
    FOREIGN KEY (niveau_id)  REFERENCES niveaux(id)  ON DELETE SET NULL,
    FOREIGN KEY (annee_id)   REFERENCES annees_academiques(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- MATIÈRES
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS matieres (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id       INT          NOT NULL,
    code           VARCHAR(20)  NOT NULL,
    nom            VARCHAR(200) NOT NULL,
    filiere_id     INT,
    niveau_id      INT,
    semestre_id    INT,
    coefficient    DECIMAL(4,2) DEFAULT 1,
    volume_horaire INT          DEFAULT 0,
    enseignant_id  INT,
    formule_calcul VARCHAR(20)  NOT NULL DEFAULT 'pondere',
    seuil_reussite DECIMAL(4,2) DEFAULT 10,
    actif          TINYINT(1)   DEFAULT 1,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_code_ecole (code, ecole_id),
    INDEX idx_ecole_id (ecole_id),
    FOREIGN KEY (filiere_id)   REFERENCES filieres(id)   ON DELETE SET NULL,
    FOREIGN KEY (niveau_id)    REFERENCES niveaux(id)    ON DELETE SET NULL,
    FOREIGN KEY (semestre_id)  REFERENCES semestres(id)  ON DELETE SET NULL,
    FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- NOTES
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS notes (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    etudiant_id INT    NOT NULL,
    matiere_id  INT    NOT NULL,
    annee_id    INT    NOT NULL,
    semestre_id INT    NULL,
    session     TINYINT NOT NULL DEFAULT 1,
    note_cc     DECIMAL(5,2) DEFAULT NULL,
    note_exam   DECIMAL(5,2) DEFAULT NULL,
    note_finale DECIMAL(5,2) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_note (etudiant_id, matiere_id, annee_id, semestre_id, session),
    FOREIGN KEY (etudiant_id) REFERENCES etudiants(id) ON DELETE CASCADE,
    FOREIGN KEY (matiere_id)  REFERENCES matieres(id)  ON DELETE CASCADE,
    FOREIGN KEY (annee_id)    REFERENCES annees_academiques(id),
    FOREIGN KEY (semestre_id) REFERENCES semestres(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- TYPES DE FRAIS
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS types_frais (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    nom            VARCHAR(100) NOT NULL,
    montant_defaut DECIMAL(10,2) DEFAULT 0,
    description    TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- PAIEMENTS ÉTUDIANTS
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS paiements_etudiants (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    etudiant_id    INT          NOT NULL,
    annee_id       INT,
    type_frais_id  INT,
    libelle        VARCHAR(200),
    montant        DECIMAL(10,2) NOT NULL,
    montant_paye   DECIMAL(10,2) DEFAULT 0,
    numero_recu    VARCHAR(20)  NULL,
    date_paiement  DATE,
    mode_paiement  ENUM('especes','cheque','virement','mobile_money') DEFAULT 'especes',
    reference      VARCHAR(100),
    statut         ENUM('en_attente','partiel','complet') DEFAULT 'en_attente',
    notes          TEXT,
    created_by     INT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (etudiant_id) REFERENCES etudiants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- VERSEMENTS
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS versements (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    paiement_id   INT     NOT NULL,
    etudiant_id   INT     NOT NULL,
    num_versement TINYINT NOT NULL DEFAULT 1,
    montant       DECIMAL(10,2) NOT NULL,
    date_versement DATE   NOT NULL,
    mode_paiement VARCHAR(50)  DEFAULT 'especes',
    reference     VARCHAR(100) NULL,
    created_by    INT          NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paiement_id) REFERENCES paiements_etudiants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- PAIEMENTS ENSEIGNANTS
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS paiements_enseignants (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    enseignant_id INT          NOT NULL,
    annee_id      INT,
    semestre_id   INT,
    libelle       VARCHAR(200) NOT NULL,
    type          ENUM('salaire','prime','vacation','autre') DEFAULT 'salaire',
    montant       DECIMAL(10,2) NOT NULL,
    date_paiement DATE,
    mode_paiement ENUM('especes','cheque','virement') DEFAULT 'virement',
    reference     VARCHAR(100),
    notes         TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- RECETTES
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS recettes (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id      INT          NOT NULL,
    annee_id      INT,
    date_recette  DATE         NOT NULL,
    libelle       VARCHAR(200) NOT NULL,
    categorie     ENUM('inscription','scolarite','examen','autre') DEFAULT 'autre',
    montant       DECIMAL(10,2) NOT NULL,
    mode_paiement ENUM('especes','cheque','virement','mobile_money') DEFAULT 'especes',
    reference     VARCHAR(100),
    notes         TEXT,
    created_by    INT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ecole_id (ecole_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- DÉPENSES
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS depenses (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id      INT          NOT NULL,
    annee_id      INT,
    date_depense  DATE         NOT NULL,
    libelle       VARCHAR(200) NOT NULL,
    categorie     ENUM('salaire','fournitures','equipement','loyer','electricite','eau','internet','autre') DEFAULT 'autre',
    montant       DECIMAL(10,2) NOT NULL,
    beneficiaire  VARCHAR(200),
    mode_paiement ENUM('especes','cheque','virement') DEFAULT 'especes',
    statut        ENUM('en_attente','approuvee','rejetee') NOT NULL DEFAULT 'approuvee',
    approuve_par  INT  NULL,
    approuve_at   DATETIME NULL,
    note_rejet    TEXT NULL,
    reference     VARCHAR(100),
    notes         TEXT,
    created_by    INT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ecole_id (ecole_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- HEURES DE COURS
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS heures_cours (
    id                 INT PRIMARY KEY AUTO_INCREMENT,
    enseignant_id      INT NOT NULL,
    matiere_id         INT NOT NULL,
    annee_id           INT NOT NULL,
    semestre_id        INT NOT NULL,
    heures_prevues     INT          DEFAULT 0,
    heures_effectuees  INT          DEFAULT 0,
    taux_horaire       DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (enseignant_id) REFERENCES enseignants(id),
    FOREIGN KEY (matiere_id)    REFERENCES matieres(id),
    FOREIGN KEY (annee_id)      REFERENCES annees_academiques(id),
    FOREIGN KEY (semestre_id)   REFERENCES semestres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- PARAMÈTRES  (scopés par école — index unique composite)
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS parametres (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    ecole_id   INT          NOT NULL,
    cle        VARCHAR(100) NOT NULL,
    valeur     TEXT,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cle_ecole (cle, ecole_id),
    INDEX idx_ecole_id (ecole_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- COMPTE SUPERADMIN (seul compte créé au démarrage)
--
-- Email    : superadmin@edupro.sys
-- Password : Admin@2025
-- Hash généré avec password_hash(..., PASSWORD_DEFAULT) PHP 8.2
--
-- Toutes les écoles, admins et données métier sont à créer
-- depuis l'interface superadmin après la première connexion.
-- ════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO users (nom, prenom, email, password, role, ecole_id, actif) VALUES
('Système', 'SuperAdmin', 'superadmin@edupro.sys', '$2y$10$aTKn72sndcwRZ2pFxBi.ve41.WPUu/guirIK1uLE.eOALwgRwBwqS', 'superadmin', NULL, 1);
