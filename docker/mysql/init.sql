CREATE DATABASE IF NOT EXISTS `edupro` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `edupro`;

CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','directeur','scolarite','enseignant','comptable','etudiant','coordinateur') NOT NULL DEFAULT 'etudiant',
    reference_id INT NULL,
    actif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS filieres (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    nom VARCHAR(200) NOT NULL,
    description TEXT,
    duree_annees INT NOT NULL DEFAULT 3,
    actif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS niveaux (
    id INT PRIMARY KEY AUTO_INCREMENT,
    filiere_id INT NOT NULL,
    nom VARCHAR(50) NOT NULL,
    ordre INT NOT NULL DEFAULT 1,
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS annees_academiques (
    id INT PRIMARY KEY AUTO_INCREMENT,
    libelle VARCHAR(20) NOT NULL,
    date_debut DATE,
    date_fin DATE,
    actif TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS semestres (
    id INT PRIMARY KEY AUTO_INCREMENT,
    annee_id INT NOT NULL,
    nom VARCHAR(50) NOT NULL,
    date_debut DATE,
    date_fin DATE,
    actif TINYINT(1) DEFAULT 0,
    FOREIGN KEY (annee_id) REFERENCES annees_academiques(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS enseignants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    matricule VARCHAR(20) UNIQUE NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    sexe ENUM('M','F') NOT NULL,
    date_naissance DATE,
    telephone VARCHAR(20),
    email VARCHAR(150),
    adresse TEXT,
    specialite VARCHAR(200),
    type_contrat ENUM('permanent','vacataire') DEFAULT 'permanent',
    salaire_base DECIMAL(10,2) DEFAULT 0,
    date_recrutement DATE,
    actif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS etudiants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    matricule VARCHAR(20) UNIQUE NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    sexe ENUM('M','F') NOT NULL,
    date_naissance DATE,
    lieu_naissance VARCHAR(100),
    telephone VARCHAR(20),
    email VARCHAR(150),
    adresse TEXT,
    nom_tuteur VARCHAR(200),
    telephone_tuteur VARCHAR(20),
    filiere_id INT,
    niveau_id INT,
    annee_id INT,
    statut ENUM('actif','transfere','exclu','diplome') DEFAULT 'actif',
    photo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE SET NULL,
    FOREIGN KEY (niveau_id) REFERENCES niveaux(id) ON DELETE SET NULL,
    FOREIGN KEY (annee_id) REFERENCES annees_academiques(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS matieres (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    nom VARCHAR(200) NOT NULL,
    filiere_id INT,
    niveau_id INT,
    semestre_id INT,
    coefficient DECIMAL(4,2) DEFAULT 1,
    volume_horaire INT DEFAULT 0,
    enseignant_id INT,
    formule_calcul VARCHAR(20) NOT NULL DEFAULT 'pondere',
    seuil_reussite DECIMAL(4,2) DEFAULT 10,
    actif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (filiere_id) REFERENCES filieres(id) ON DELETE SET NULL,
    FOREIGN KEY (niveau_id) REFERENCES niveaux(id) ON DELETE SET NULL,
    FOREIGN KEY (semestre_id) REFERENCES semestres(id) ON DELETE SET NULL,
    FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    etudiant_id INT NOT NULL,
    matiere_id INT NOT NULL,
    annee_id INT NOT NULL,
    semestre_id INT NULL,
    session TINYINT NOT NULL DEFAULT 1,
    note_cc DECIMAL(5,2) DEFAULT NULL,
    note_exam DECIMAL(5,2) DEFAULT NULL,
    note_finale DECIMAL(5,2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_note (etudiant_id, matiere_id, annee_id, semestre_id, session),
    FOREIGN KEY (etudiant_id) REFERENCES etudiants(id) ON DELETE CASCADE,
    FOREIGN KEY (matiere_id) REFERENCES matieres(id) ON DELETE CASCADE,
    FOREIGN KEY (annee_id) REFERENCES annees_academiques(id),
    FOREIGN KEY (semestre_id) REFERENCES semestres(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS types_frais (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(100) NOT NULL,
    montant_defaut DECIMAL(10,2) DEFAULT 0,
    description TEXT
);

CREATE TABLE IF NOT EXISTS paiements_etudiants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    etudiant_id INT NOT NULL,
    annee_id INT,
    type_frais_id INT,
    libelle VARCHAR(200),
    montant DECIMAL(10,2) NOT NULL,
    montant_paye DECIMAL(10,2) DEFAULT 0,
    numero_recu VARCHAR(20) NULL,
    date_paiement DATE,
    mode_paiement ENUM('especes','cheque','virement','mobile_money') DEFAULT 'especes',
    reference VARCHAR(100),
    statut ENUM('en_attente','partiel','complet') DEFAULT 'en_attente',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (etudiant_id) REFERENCES etudiants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS versements (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    paiement_id     INT NOT NULL,
    etudiant_id     INT NOT NULL,
    num_versement   TINYINT NOT NULL DEFAULT 1,
    montant         DECIMAL(10,2) NOT NULL,
    date_versement  DATE NOT NULL,
    mode_paiement   VARCHAR(50) DEFAULT 'especes',
    reference       VARCHAR(100) NULL,
    created_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paiement_id) REFERENCES paiements_etudiants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS paiements_enseignants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enseignant_id INT NOT NULL,
    annee_id INT,
    semestre_id INT,
    libelle VARCHAR(200) NOT NULL,
    type ENUM('salaire','prime','vacation','autre') DEFAULT 'salaire',
    montant DECIMAL(10,2) NOT NULL,
    date_paiement DATE,
    mode_paiement ENUM('especes','cheque','virement') DEFAULT 'virement',
    reference VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS recettes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    annee_id INT,
    date_recette DATE NOT NULL,
    libelle VARCHAR(200) NOT NULL,
    categorie ENUM('inscription','scolarite','examen','autre') DEFAULT 'autre',
    montant DECIMAL(10,2) NOT NULL,
    mode_paiement ENUM('especes','cheque','virement','mobile_money') DEFAULT 'especes',
    reference VARCHAR(100),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS depenses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    annee_id INT,
    date_depense DATE NOT NULL,
    libelle VARCHAR(200) NOT NULL,
    categorie ENUM('salaire','fournitures','equipement','loyer','electricite','eau','internet','autre') DEFAULT 'autre',
    montant DECIMAL(10,2) NOT NULL,
    beneficiaire VARCHAR(200),
    mode_paiement ENUM('especes','cheque','virement') DEFAULT 'especes',
    statut ENUM('en_attente','approuvee','rejetee') NOT NULL DEFAULT 'approuvee',
    approuve_par INT NULL,
    approuve_at DATETIME NULL,
    note_rejet TEXT NULL,
    reference VARCHAR(100),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS heures_cours (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enseignant_id INT NOT NULL,
    matiere_id INT NOT NULL,
    annee_id INT NOT NULL,
    semestre_id INT NOT NULL,
    heures_prevues INT DEFAULT 0,
    heures_effectuees INT DEFAULT 0,
    taux_horaire DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (enseignant_id) REFERENCES enseignants(id),
    FOREIGN KEY (matiere_id) REFERENCES matieres(id),
    FOREIGN KEY (annee_id) REFERENCES annees_academiques(id),
    FOREIGN KEY (semestre_id) REFERENCES semestres(id)
);

CREATE TABLE IF NOT EXISTS parametres (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cle VARCHAR(100) UNIQUE NOT NULL,
    valeur TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Filières
INSERT IGNORE INTO filieres (code, nom, description, duree_annees) VALUES
('ASB', 'Agent de Santé de Base', 'Formation d\'agent de santé de base communautaire', 3),
('INF', 'Licence Sciences Infirmières – Infirmier', 'Licence en sciences infirmières et obstétricales – option Infirmier', 3),
('SF',  'Licence Sciences Infirmières – Sage-Femme', 'Licence en sciences infirmières et obstétricales – option Sage-Femme', 3),
('VP',  'Vendeur en Pharmacie', 'Formation de vendeur/préparateur en officine pharmaceutique', 1);

-- Niveaux (ASB=1, INF=2, SF=3 → 3 ans ; VP=4 → 1 an)
INSERT IGNORE INTO niveaux (filiere_id, nom, ordre) VALUES
(1,'Année 1',1),(1,'Année 2',2),(1,'Année 3',3),
(2,'Année 1',1),(2,'Année 2',2),(2,'Année 3',3),
(3,'Année 1',1),(3,'Année 2',2),(3,'Année 3',3),
(4,'Année 1',1);

-- Années académiques
INSERT IGNORE INTO annees_academiques (libelle, date_debut, date_fin, actif) VALUES
('2024-2025','2024-10-01','2025-07-31',1),
('2025-2026','2025-10-01','2026-07-31',0);

-- Semestres (annee_id=1 = 2024-2025)
INSERT IGNORE INTO semestres (annee_id, nom, date_debut, date_fin, actif) VALUES
(1,'Semestre 1','2024-10-01','2025-01-31',1),
(1,'Semestre 2','2025-02-01','2025-07-31',0);

-- Types de frais
INSERT IGNORE INTO types_frais (nom, montant_defaut, description) VALUES
('Droits d\'inscription', 50000,  'Frais d\'inscription au dossier'),
('Frais de scolarité',    300000, 'Frais de scolarité annuels'),
('Frais d\'examen',       25000,  'Frais de passage d\'examen');

-- Paramètres
INSERT IGNORE INTO parametres (cle, valeur) VALUES
('etablissement_nom',       'E-EDU PRO'),
('etablissement_slogan',    'Excellence – Éducation – Service'),
('etablissement_adresse',   'Tahoua, Niger'),
('etablissement_telephone', ''),
('etablissement_email',     ''),
('theme_couleur_primaire',  '#1a73e8'),
('theme_couleur_sidebar',   '#0f2d5c'),
('logo_path',               '');

-- Compte administrateur (mot de passe : Admin@2025)
INSERT IGNORE INTO users (nom, prenom, email, password, role, actif) VALUES
('EDUPRO', 'Administrateur', 'admin@epsi.sn', '$2y$10$aTKn72sndcwRZ2pFxBi.ve41.WPUu/guirIK1uLE.eOALwgRwBwqS', 'admin', 1);
