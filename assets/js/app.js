/* ===== EDUPRO App JS ===== */
'use strict';

// L'état actif des liens est géré côté serveur (sidebar.php) — aucune logique JS nécessaire ici.

// Confirm delete
function confirmDelete(form, name) {
  if (confirm('Confirmer la suppression de "' + name + '" ?\nCette action est irréversible.')) {
    form.submit();
  }
}

// Sidebar toggle on mobile
const sidebarToggle = document.getElementById('sidebarToggle');
if (sidebarToggle) {
  sidebarToggle.addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('show');
  });
}

// Auto-dismiss alerts after 5s
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(el => {
  setTimeout(() => {
    const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
    if (bsAlert) bsAlert.close();
  }, 5000);
});

// Filiere → Niveau AJAX dependency
const filiereSelect = document.getElementById('filiere_id');
const niveauSelect  = document.getElementById('niveau_id');
if (filiereSelect && niveauSelect) {
  filiereSelect.addEventListener('change', function () {
    const filiereId = this.value;
    niveauSelect.innerHTML = '<option value="">Chargement...</option>';
    if (!filiereId) {
      niveauSelect.innerHTML = '<option value="">-- Sélectionner une filière d\'abord --</option>';
      return;
    }
    fetch('/SCO-EPSI/api/niveaux.php?filiere_id=' + filiereId)
      .then(r => r.json())
      .then(data => {
        niveauSelect.innerHTML = '<option value="">-- Sélectionner un niveau --</option>';
        data.forEach(n => {
          const opt = document.createElement('option');
          opt.value = n.id;
          opt.textContent = n.nom;
          niveauSelect.appendChild(opt);
        });
      })
      .catch(() => {
        niveauSelect.innerHTML = '<option value="">Erreur de chargement</option>';
      });
  });
}

// Annee → Semestre dependency
const anneeSelect    = document.getElementById('annee_id');
const semestreSelect = document.getElementById('semestre_id');
if (anneeSelect && semestreSelect) {
  anneeSelect.addEventListener('change', function () {
    const anneeId = this.value;
    semestreSelect.innerHTML = '<option value="">Chargement...</option>';
    if (!anneeId) {
      semestreSelect.innerHTML = '<option value="">-- Sélectionner une année d\'abord --</option>';
      return;
    }
    fetch('/SCO-EPSI/api/semestres.php?annee_id=' + anneeId)
      .then(r => r.json())
      .then(data => {
        semestreSelect.innerHTML = '<option value="">-- Sélectionner un semestre --</option>';
        data.forEach(s => {
          const opt = document.createElement('option');
          opt.value = s.id;
          opt.textContent = s.nom;
          semestreSelect.appendChild(opt);
        });
      });
  });
}

// Print bulletin
function printBulletin() {
  window.print();
}

// Table search filter
const tableSearch = document.getElementById('tableSearch');
if (tableSearch) {
  tableSearch.addEventListener('input', function () {
    const query = this.value.toLowerCase().trim();
    document.querySelectorAll('#dataTable tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
  });
}

// Tooltip init
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
  new bootstrap.Tooltip(el);
});

// Note calculation (CC 40% + Exam 60%)
function calculateNoteFinale() {
  const cc   = parseFloat(document.getElementById('note_cc')?.value)   || 0;
  const exam = parseFloat(document.getElementById('note_exam')?.value) || 0;
  const input = document.getElementById('note_finale');
  if (input) {
    const finale = (cc * 0.4 + exam * 0.6).toFixed(2);
    input.value = finale;
  }
}
document.getElementById('note_cc')?.addEventListener('input', calculateNoteFinale);
document.getElementById('note_exam')?.addEventListener('input', calculateNoteFinale);
