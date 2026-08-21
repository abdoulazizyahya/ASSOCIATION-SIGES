<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
exiger_connexion();

// ── Créer le dossier de photos de profil si absent ───────────────
$profil_dir = __DIR__ . '/assets/uploads/profils/';
if (!is_dir($profil_dir)) @mkdir($profil_dir, 0755, true);

// ── Ajouter colonne photo si absente ─────────────────────────────
// "ADD COLUMN IF NOT EXISTS" n'est pas supporté par ce serveur MySQL (erreur
// de syntaxe) : la colonne n'était donc jamais créée et la sauvegarde de la
// photo échouait silencieusement. On vérifie explicitement son existence.
try {
    $colonne_photo_existe = db_val(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='utilisateur' AND column_name='photo'"
    );
    if (!$colonne_photo_existe) {
        mysqli_query($link, "ALTER TABLE utilisateur ADD COLUMN photo VARCHAR(255) NULL DEFAULT NULL");
    }
} catch (Exception $e) { /* silencieux */ }

$role        = role_connecte();
$is_admin    = ($role === 'ADMIN');
$onglet      = $_GET['onglet'] ?? 'profil';
$user_id     = (int)$_SESSION['user_id'];
$user        = db_one("SELECT u.*, e.nom_ens, e.prenom_ens FROM utilisateur u
                        LEFT JOIN enseignant e ON e.matricule_ens=u.matricule_ens
                        WHERE u.id=?", [$user_id]);

// ══════════════════════════════════════════════════
//  POST — Mon profil
// ══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'update_profil') {
        $nom    = post('nom');
        $prenom = post('prenom');
        $email  = post('email');
        $login  = post('login');
        $mdp    = post('mot_de_passe');
        $conf   = post('confirmer_mdp');

        if (!$nom)   { flash_set('erreur', 'Le nom est obligatoire.');   rediriger('profil.php?onglet=profil'); }
        if (!$login) { flash_set('erreur', 'Le login est obligatoire.'); rediriger('profil.php?onglet=profil'); }

        $exist = db_val("SELECT id FROM utilisateur WHERE login=? AND id!=?", [$login, $user_id]);
        if ($exist) { flash_set('erreur', 'Ce login est déjà utilisé.'); rediriger('profil.php?onglet=profil'); }

        // ── Photo de profil (base64 depuis Cropper.js) ──────────
        $photo_b64 = post('photo_b64');
        $photo_nom = null;
        if ($photo_b64 && str_starts_with($photo_b64, 'data:image/')) {
            if (preg_match('/data:image\/(\w+);base64,(.+)/s', $photo_b64, $mx)) {
                $ext  = strtolower($mx[1]) === 'png' ? 'png' : 'jpg';
                $data = base64_decode($mx[2]);
                if ($data && strlen($data) <= 3 * 1024 * 1024) {
                    // Supprimer ancienne photo
                    $old = db_val("SELECT photo FROM utilisateur WHERE id=?", [$user_id]);
                    if ($old && file_exists($profil_dir.$old)) @unlink($profil_dir.$old);
                    $photo_nom = 'usr_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    file_put_contents($profil_dir . $photo_nom, $data);
                }
            }
        }

        if ($mdp) {
            if ($mdp !== $conf) { flash_set('erreur', 'Les mots de passe ne correspondent pas.'); rediriger('profil.php?onglet=profil'); }
            $mdp_hash = password_hash($mdp, PASSWORD_DEFAULT);
            if ($photo_nom) {
                db_exec("UPDATE utilisateur SET nom=?,prenom=?,email=?,login=?,mot_de_passe=?,photo=? WHERE id=?",
                        [$nom,$prenom,$email,$login,$mdp_hash,$photo_nom,$user_id]);
            } else {
                db_exec("UPDATE utilisateur SET nom=?,prenom=?,email=?,login=?,mot_de_passe=? WHERE id=?",
                        [$nom,$prenom,$email,$login,$mdp_hash,$user_id]);
            }
        } else {
            if ($photo_nom) {
                db_exec("UPDATE utilisateur SET nom=?,prenom=?,email=?,login=?,photo=? WHERE id=?",
                        [$nom,$prenom,$email,$login,$photo_nom,$user_id]);
            } else {
                db_exec("UPDATE utilisateur SET nom=?,prenom=?,email=?,login=? WHERE id=?",
                        [$nom,$prenom,$email,$login,$user_id]);
            }
        }
        $_SESSION['user']['nom'] = $nom;
        flash_set('succes', 'Profil mis à jour.');
        rediriger('profil.php?onglet=profil');
    }

    // ── Gestion utilisateurs (ADMIN) ──────────────
    if ($is_admin) {
        if ($action === 'user_changer_mdp') {
            $uid   = (int)post('user_id');
            $login_cible = post('login');
            $mdp   = post('new_mdp');
            if ($uid && $mdp) {
                db_exec("UPDATE utilisateur SET mot_de_passe=? WHERE id=?", [password_hash($mdp, PASSWORD_DEFAULT), $uid]);
                flash_set('succes', "Mot de passe réinitialisé pour « {$login_cible} » : {$mdp} — communiquez-le à l'utilisateur, il ne sera plus affichable ensuite.");
            }
            rediriger('profil.php?onglet=utilisateurs');
        }
        if ($action === 'user_changer_role') {
            $uid  = (int)post('user_id');
            $role_new = post('new_role');
            if ($uid && $role_new) {
                db_exec("UPDATE utilisateur SET role=? WHERE id=?", [$role_new, $uid]);
                flash_set('succes', 'Rôle mis à jour.');
            }
            rediriger('profil.php?onglet=utilisateurs');
        }
        if ($action === 'user_supprimer') {
            $uid = (int)post('user_id');
            if ($uid && $uid !== $user_id) {
                db_exec("DELETE FROM utilisateur WHERE id=?", [$uid]);
                flash_set('succes', 'Utilisateur supprimé.');
            }
            rediriger('profil.php?onglet=utilisateurs');
        }
        if ($action === 'user_modifier') {
            $uid    = (int)post('user_id');
            $nom    = post('nom');
            $prenom = post('prenom');
            $login  = post('login');
            $role_n = post('role');
            $mdp    = post('mot_de_passe');
            if ($uid && $nom && $login) {
                if ($mdp) {
                    db_exec("UPDATE utilisateur SET nom=?,prenom=?,login=?,role=?,mot_de_passe=? WHERE id=?",
                            [$nom,$prenom,$login,$role_n,password_hash($mdp, PASSWORD_DEFAULT),$uid]);
                } else {
                    db_exec("UPDATE utilisateur SET nom=?,prenom=?,login=?,role=? WHERE id=?",
                            [$nom,$prenom,$login,$role_n,$uid]);
                }
                flash_set('succes', 'Utilisateur mis à jour.');
            }
            rediriger('profil.php?onglet=utilisateurs');
        }
    }
}

// Données admin
$tous_users = [];
$roles_list = ['ADMIN','PROVISEUR','CENSEUR','SG','SECRETAIRE','ENSEIGNANT'];
if ($is_admin && $onglet === 'utilisateurs') {
    $q_user   = trim($_GET['q_user'] ?? '');
    $f_role   = trim($_GET['f_role'] ?? '');
    $sql      = "SELECT u.*, e.nom_ens, e.prenom_ens
                 FROM utilisateur u
                 LEFT JOIN enseignant e ON e.matricule_ens=u.matricule_ens
                 WHERE 1=1";
    $params   = [];
    if ($q_user) {
        $like   = '%'.$q_user.'%';
        $sql   .= " AND (u.nom LIKE ? OR u.prenom LIKE ? OR u.login LIKE ?)";
        $params = array_merge($params, [$like, $like, $like]);
    }
    if ($f_role) { $sql .= " AND u.role=?"; $params[] = $f_role; }
    $sql .= " ORDER BY u.role, u.nom, u.prenom";
    $tous_users = db_all($sql, $params);
}

$titre_page = 'Mon profil';
require_once __DIR__ . '/layout/header.php';

// initiales avatar
$initiales = mb_strtoupper(mb_substr($user['nom'] ?? '?', 0, 1)) . mb_strtoupper(mb_substr($user['prenom'] ?? '', 0, 1));

$role_colors = [
    'ADMIN'      => ['bg'=>'#fee2e2','txt'=>'#991b1b'],
    'PROVISEUR'  => ['bg'=>'#dbeafe','txt'=>'#1e40af'],
    'CENSEUR'    => ['bg'=>'#e0e7ff','txt'=>'#3730a3'],
    'SG'         => ['bg'=>'#fef3c7','txt'=>'#92400e'],
    'SECRETAIRE' => ['bg'=>'#f0fdf4','txt'=>'#166534'],
    'ENSEIGNANT' => ['bg'=>'#fdf4ff','txt'=>'#6b21a8'],
];
function role_badge(string $r): string {
    global $role_colors;
    $c = $role_colors[$r] ?? ['bg'=>'#f3f4f6','txt'=>'#374151'];
    return '<span style="background:'.$c['bg'].';color:'.$c['txt'].';padding:2px 9px;border-radius:10px;font-size:.68rem;font-weight:700">'.htmlspecialchars($r, ENT_QUOTES, 'UTF-8').'</span>';
}
?>

<div class="page-titre d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-person-circle me-1 text-primary"></i>Profil &amp; Utilisateurs</h4>
</div>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='profil'?'active':'' ?>" href="<?= APP_URL ?>/profil.php?onglet=profil">
      <i class="bi bi-person me-1"></i>Mon profil
    </a>
  </li>
  <?php if ($is_admin): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='utilisateurs'?'active':'' ?>" href="<?= APP_URL ?>/profil.php?onglet=utilisateurs">
      <i class="bi bi-people me-1"></i>Utilisateurs
    </a>
  </li>
  <?php endif; ?>
</ul>


<?php if ($onglet === 'profil'): ?>
<!-- ══ Mon profil ══ -->

<div class="row g-3 align-items-start">

  <!-- Carte identité -->
  <div class="col-lg-4 col-md-5">
    <div class="card text-center" style="overflow:hidden">
      <div style="height:75px;background:linear-gradient(135deg,var(--primary),#7c3aed)"></div>
      <div class="card-body pt-0 pb-3">
        <!-- Avatar cliquable -->
        <div style="position:relative;width:90px;margin:-45px auto 12px">
          <?php if (!empty($user['photo'])): ?>
            <img id="avatar-display"
                 src="<?= APP_URL ?>/assets/uploads/profils/<?= h($user['photo']) ?>"
                 style="width:90px;height:90px;border-radius:50%;object-fit:cover;
                        border:3px solid #fff;box-shadow:0 4px 16px rgba(0,0,0,.2);display:block">
          <?php else: ?>
            <div id="avatar-display"
                 style="width:90px;height:90px;border-radius:50%;
                        background:linear-gradient(135deg,var(--primary),#7c3aed);
                        display:flex;align-items:center;justify-content:center;
                        font-size:1.8rem;font-weight:800;color:#fff;
                        box-shadow:0 4px 16px rgba(0,0,0,.2);border:3px solid #fff">
              <?= h($initiales) ?>
            </div>
          <?php endif; ?>
          <!-- Bouton caméra overlay -->
          <label for="photo-picker"
                 style="position:absolute;bottom:2px;right:2px;width:26px;height:26px;
                        border-radius:50%;background:var(--primary);color:#fff;
                        display:flex;align-items:center;justify-content:center;
                        cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.3);border:2px solid #fff"
                 title="Changer la photo">
            <i class="bi bi-camera-fill" style="font-size:.65rem"></i>
          </label>
          <input type="file" id="photo-picker" accept="image/*" style="display:none">
        </div>
        <div class="fw-bold" style="font-size:1rem;color:#1e2a3a">
          <?= h($user['nom']) ?> <?= h($user['prenom'] ?? '') ?>
        </div>
        <div class="text-muted mb-2" style="font-size:.78rem">
          <i class="bi bi-at"></i><?= h($user['login']) ?>
        </div>
        <?= role_badge($user['role']) ?>

        <!-- Questions de sécurité -->
        <div class="mt-3 pt-2 border-top">
          <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:4px">
            <i class="bi bi-shield-lock me-1"></i>Sécurité
          </div>
          <?php if (utilisateur_a_questions($user_id)): ?>
            <span class="badge bg-success" style="font-size:.68rem">Questions configurées</span>
          <?php else: ?>
            <span class="badge bg-warning text-dark" style="font-size:.68rem">Non configurées</span>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/configurer_securite.php?retour=profil.php" class="btn btn-light btn-sm d-block mt-2" style="font-size:.72rem">
            <i class="bi bi-pencil me-1"></i>Modifier mes questions
          </a>
        </div>

        <?php if (!empty($user['nom_ens'])): ?>
        <div class="mt-2 pt-2 border-top" style="font-size:.75rem;color:#6b7280">
          <i class="bi bi-person-badge me-1"></i>
          <?= h($user['nom_ens'].' '.($user['prenom_ens']??'')) ?>
          <div style="font-size:.7rem;color:#9ca3af">Enseignant lié</div>
        </div>
        <?php endif; ?>
        <?php if (!empty($user['email'])): ?>
        <div class="mt-2" style="font-size:.73rem;color:#6b7280">
          <i class="bi bi-envelope me-1"></i><?= h($user['email']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Formulaire modification -->
  <div class="col-lg-8 col-md-7">
    <div class="card">
      <div class="card-header py-2 d-flex align-items-center gap-2" style="background:#f8faff">
        <i class="bi bi-pencil-square text-primary"></i>
        <span class="fw-semibold" style="font-size:.82rem">Modifier mes informations</span>
      </div>
      <div class="card-body">
        <form method="post" class="row g-compact" enctype="multipart/form-data">
          <?= csrf_champ() ?>
          <input type="hidden" name="action"    value="update_profil">
          <input type="hidden" name="photo_b64" id="photo_b64_inp">

          <!-- Identité -->
          <div class="col-12">
            <div class="section-titre mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af">
              Identité
            </div>
          </div>
          <div class="col-md-5">
            <label class="form-label">Nom <span class="text-danger">*</span></label>
            <input type="text" name="nom" class="form-control" required value="<?= h($user['nom']) ?>">
          </div>
          <div class="col-md-7">
            <label class="form-label">Prénom</label>
            <input type="text" name="prenom" class="form-control" value="<?= h($user['prenom'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($user['email'] ?? '') ?>">
          </div>

          <!-- Connexion -->
          <div class="col-12 mt-1">
            <div class="section-titre mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af">
              Connexion
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nom d'utilisateur (login) <span class="text-danger">*</span></label>
            <div class="input-group input-group-sm">
              <span class="input-group-text" style="background:#f3f4f6"><i class="bi bi-at"></i></span>
              <input type="text" name="login" class="form-control" required value="<?= h($user['login']) ?>">
            </div>
          </div>

          <!-- Mot de passe -->
          <div class="col-12 mt-1">
            <div class="section-titre mb-1 d-flex align-items-center gap-2"
                 style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af">
              Mot de passe
              <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#d1d5db">— Laisser vide pour ne pas changer</span>
            </div>
          </div>
          <div class="col-md-5">
            <label class="form-label">Nouveau mot de passe</label>
            <div class="input-group input-group-sm">
              <input type="text" name="mot_de_passe" id="inp-mdp" class="form-control" autocomplete="new-password" placeholder="••••••">
              <button type="button" class="btn btn-light" style="border:1px solid #dee2e6"
                      onclick="toggleMdp('inp-mdp',this)">
                <i class="bi bi-eye" style="font-size:.72rem"></i>
              </button>
            </div>
          </div>
          <div class="col-md-5">
            <label class="form-label">Confirmer</label>
            <div class="input-group input-group-sm">
              <input type="text" name="confirmer_mdp" id="inp-conf" class="form-control" autocomplete="new-password" placeholder="••••••">
              <button type="button" class="btn btn-light" style="border:1px solid #dee2e6"
                      onclick="toggleMdp('inp-conf',this)">
                <i class="bi bi-eye" style="font-size:.72rem"></i>
              </button>
            </div>
          </div>

          <div class="col-12 mt-2 d-flex align-items-center gap-2">
            <button class="btn btn-primary btn-sm px-4">
              <i class="bi bi-check-lg me-1"></i>Enregistrer
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Cropper.js -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>

<!-- Modal Cropper -->
<div class="modal fade" id="modalCropper" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden">
      <div class="modal-header py-2" style="background:linear-gradient(135deg,#1e2a3a,#2d3a52)">
        <h6 class="modal-title fw-bold text-white d-flex align-items-center gap-2" style="font-size:.88rem">
          <i class="bi bi-crop text-info"></i>Ajuster la photo de profil
        </h6>
        <button type="button" class="btn-close btn-close-white btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" style="background:#1a1a2e">
        <!-- Zone Cropper -->
        <div style="max-height:420px;overflow:hidden;display:flex;align-items:center;justify-content:center">
          <img id="crop-img" style="max-width:100%;display:block">
        </div>
      </div>
      <!-- Barre d'outils -->
      <div style="background:#1e2a3a;padding:10px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <div style="display:flex;gap:5px">
          <button type="button" class="btn btn-sm btn-dark" onclick="cropperInst.zoom(0.1)" title="Zoom +">
            <i class="bi bi-zoom-in"></i>
          </button>
          <button type="button" class="btn btn-sm btn-dark" onclick="cropperInst.zoom(-0.1)" title="Zoom -">
            <i class="bi bi-zoom-out"></i>
          </button>
        </div>
        <div style="display:flex;gap:5px">
          <button type="button" class="btn btn-sm btn-dark" onclick="cropperInst.rotate(-90)" title="Pivoter gauche">
            <i class="bi bi-arrow-counterclockwise"></i>
          </button>
          <button type="button" class="btn btn-sm btn-dark" onclick="cropperInst.rotate(90)" title="Pivoter droite">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
        </div>
        <div style="display:flex;gap:5px">
          <button type="button" class="btn btn-sm btn-dark" onclick="cropperInst.scaleX(-cropperInst.getData().scaleX||1)" title="Miroir H">
            <i class="bi bi-symmetry-vertical"></i>
          </button>
          <button type="button" class="btn btn-sm btn-dark" onclick="cropperInst.reset()" title="Réinitialiser">
            <i class="bi bi-arrow-repeat"></i>
          </button>
        </div>
        <div style="flex:1;margin-left:8px">
          <label style="color:#94a3b8;font-size:.7rem;display:block;margin-bottom:2px">
            <i class="bi bi-search me-1"></i>Zoom
          </label>
          <input type="range" id="zoom-slider" min="0" max="3" step="0.01" value="0"
                 style="width:100%;accent-color:var(--primary)"
                 oninput="cropperInst.zoomTo(parseFloat(this.value))">
        </div>
        <div class="ms-auto d-flex gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary text-white" data-bs-dismiss="modal">
            Annuler
          </button>
          <button type="button" class="btn btn-sm btn-primary" onclick="validerCrop()">
            <i class="bi bi-check-lg me-1"></i>Appliquer
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
var cropperInst = null;
var cropperModal = null;

document.getElementById('photo-picker').addEventListener('change', function(e){
    var file = e.target.files[0];
    if (!file) return;
    if (file.size > 6 * 1024 * 1024) { alert('Image trop grande (max 6 Mo).'); return; }
    var reader = new FileReader();
    reader.onload = function(ev){
        var img = document.getElementById('crop-img');
        img.src = ev.target.result;
        // Détruire instance précédente
        if (cropperInst) { cropperInst.destroy(); cropperInst = null; }
        cropperModal = cropperModal || new bootstrap.Modal(document.getElementById('modalCropper'));
        cropperModal.show();
        // Attendre que le modal soit visible
        document.getElementById('modalCropper').addEventListener('shown.bs.modal', function onShown(){
            this.removeEventListener('shown.bs.modal', onShown);
            cropperInst = new Cropper(img, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.85,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
                ready: function(){
                    // Synchro slider zoom
                    document.getElementById('zoom-slider').value = 0;
                },
                zoom: function(e){
                    var ratio = e.detail.ratio;
                    document.getElementById('zoom-slider').value = Math.max(0, Math.min(3, ratio));
                }
            });
        }, { once: true });
    };
    reader.readAsDataURL(file);
    // Reset input pour pouvoir re-choisir le même fichier
    this.value = '';
});

function validerCrop() {
    if (!cropperInst) return;
    var canvas = cropperInst.getCroppedCanvas({ width: 320, height: 320, imageSmoothingQuality: 'high' });
    var b64 = canvas.toDataURL('image/jpeg', 0.88);
    // Afficher l'aperçu immédiatement
    var av = document.getElementById('avatar-display');
    if (av.tagName === 'IMG') {
        av.src = b64;
    } else {
        var img = document.createElement('img');
        img.id = 'avatar-display';
        img.style.cssText = 'width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid #fff;box-shadow:0 4px 16px rgba(0,0,0,.2);display:block';
        av.parentNode.replaceChild(img, av);
        img.src = b64;
    }
    document.getElementById('photo_b64_inp').value = b64;
    cropperModal.hide();
    cropperInst.destroy();
    cropperInst = null;
}

function toggleMdp(id, btn) {
    var inp = document.getElementById(id);
    var isText = inp.type === 'text';
    inp.type  = isText ? 'password' : 'text';
    btn.innerHTML = isText
        ? '<i class="bi bi-eye" style="font-size:.72rem"></i>'
        : '<i class="bi bi-eye-slash" style="font-size:.72rem"></i>';
}
document.addEventListener('DOMContentLoaded', function(){
    ['inp-mdp','inp-conf'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.type = 'password';
    });
});
</script>


<?php elseif ($onglet === 'utilisateurs' && $is_admin): ?>
<!-- ══ Liste Utilisateurs (ADMIN) ══ -->

<!-- Barre recherche / filtre -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
      <input type="hidden" name="onglet" value="utilisateurs">
      <div class="d-flex align-items-center gap-1" style="flex:1;min-width:200px;max-width:300px;
           background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:3px 10px">
        <i class="bi bi-search" style="color:#9ca3af;font-size:.75rem"></i>
        <input type="text" name="q_user" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0"
               style="font-size:.78rem" placeholder="Nom, prénom, login..." value="<?= h($_GET['q_user'] ?? '') ?>">
      </div>
      <select name="f_role" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <option value="">Tous les rôles</option>
        <?php foreach ($roles_list as $rl): ?>
          <option value="<?= $rl ?>" <?= ($_GET['f_role'] ?? '') === $rl ? 'selected' : '' ?>><?= $rl ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-outline-primary btn-sm">
        <i class="bi bi-search me-1"></i>Filtrer
      </button>
      <?php if (!empty($_GET['q_user']) || !empty($_GET['f_role'])): ?>
        <a href="<?= APP_URL ?>/profil.php?onglet=utilisateurs" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-x"></i> Effacer
        </a>
      <?php endif; ?>
      <span class="ms-auto text-muted" style="font-size:.75rem;white-space:nowrap">
        <?= count($tous_users) ?> utilisateur(s)
      </span>
    </form>
  </div>
</div>

<?php
// Stats rapides
$stats = [];
foreach ($tous_users as $u) { $stats[$u['role']] = ($stats[$u['role']] ?? 0) + 1; }
?>
<div class="d-flex gap-2 flex-wrap mb-3">
  <?php foreach ($stats as $r => $nb): ?>
    <?php $c = $role_colors[$r] ?? ['bg'=>'#f3f4f6','txt'=>'#374151']; ?>
    <span style="background:<?= $c['bg'] ?>;color:<?= $c['txt'] ?>;padding:3px 10px;border-radius:10px;font-size:.72rem;font-weight:700">
      <?= $r ?> <span style="opacity:.7">(<?= $nb ?>)</span>
    </span>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-abz table-hover mb-0" style="font-size:.79rem">
        <thead>
          <tr>
            <th style="width:34px">N°</th>
            <th>Utilisateur</th>
            <th>Enseignant lié</th>
            <th style="width:65px;text-align:center">Rôle</th>
            <th style="width:120px;text-align:center">Mot de passe</th>
            <th style="width:44px;text-align:center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($tous_users)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Aucun utilisateur trouvé.</td></tr>
          <?php else: foreach ($tous_users as $i => $u): ?>
          <tr <?= $u['id'] == $user_id ? 'style="background:#fffbeb"' : '' ?>>
            <td class="text-muted"><?= $i+1 ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:7px">
                <?php if (!empty($u['photo'])): ?>
                  <img src="<?= APP_URL ?>/assets/uploads/profils/<?= h($u['photo']) ?>"
                       style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0">
                <?php else: ?>
                  <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;
                              background:linear-gradient(135deg,var(--primary),#7c3aed);
                              display:flex;align-items:center;justify-content:center;
                              font-size:.65rem;font-weight:700;color:#fff">
                    <?= mb_strtoupper(mb_substr($u['nom']??'?',0,1).mb_substr($u['prenom']??'',0,1)) ?>
                  </div>
                <?php endif; ?>
                <div>
                  <div class="fw-semibold"><?= h($u['login']) ?>
                    <?php if ($u['id'] == $user_id): ?>
                      <span style="font-size:.6rem;background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:5px;margin-left:3px">vous</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($u['email'])): ?>
                    <div style="font-size:.68rem;color:#9ca3af"><?= h($u['email']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td style="color:#6b7280;font-size:.75rem">
              <?php if (!empty($u['nom_ens'])): ?>
                <span style="display:flex;align-items:center;gap:4px">
                  <i class="bi bi-person-badge" style="font-size:.7rem"></i>
                  <?= h($u['nom_ens'].' '.($u['prenom_ens']??'')) ?>
                </span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center"><?= role_badge($u['role']) ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-light" style="padding:2px 7px;font-size:.7rem"
                      onclick="voirMdp(<?= $u['id'] ?>, <?= h(json_encode($u['login'])) ?>)"
                      title="Réinitialiser le mot de passe">
                <i class="bi bi-key me-1"></i>Réinitialiser
              </button>
            </td>
            <td class="text-center">
              <div class="dropdown">
                <button class="btn btn-sm btn-light" style="padding:2px 6px"
                        data-bs-toggle="dropdown">
                  <i class="bi bi-three-dots-vertical" style="font-size:.72rem"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.78rem;min-width:140px">
                  <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-1" href="#"
                       onclick="ouvrirEditUser(<?= h(json_encode($u)) ?>); return false">
                      <i class="bi bi-pencil" style="color:#2563eb;width:14px"></i>Modifier
                    </a>
                  </li>
                  <?php if ($u['id'] != $user_id): ?>
                  <li><hr class="dropdown-divider my-1"></li>
                  <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-1 text-danger" href="#"
                       onclick="supprimerUser(<?= $u['id'] ?>, '<?= h(addslashes($u['login'])) ?>'); return false">
                      <i class="bi bi-trash" style="width:14px"></i>Supprimer
                    </a>
                  </li>
                  <?php endif; ?>
                </ul>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal Réinitialiser MDP -->
<div class="modal fade" id="modalMdp" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold" style="font-size:.85rem">
          <i class="bi bi-key me-1 text-primary"></i>Réinitialiser le mot de passe
        </h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="action"  value="user_changer_mdp">
        <input type="hidden" name="user_id" id="mdp-uid">
        <input type="hidden" name="login"   id="mdp-login">
        <div class="modal-body">
          <div class="mb-2" style="font-size:.72rem;color:#6b7280">
            Compte : <strong id="mdp-login-affiche"></strong> — le mot de passe ne sera affiché qu'une seule fois après validation.
          </div>
          <label class="form-label">Nouveau mot de passe</label>
          <div class="input-group input-group-sm">
            <input type="text" name="new_mdp" id="new-mdp" class="form-control" required>
            <button type="button" class="btn btn-light" style="border:1px solid #dee2e6" onclick="genererMdp()" title="Générer">
              <i class="bi bi-shuffle" style="font-size:.72rem"></i>
            </button>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm">
            <i class="bi bi-check-lg me-1"></i>Modifier
          </button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Modifier utilisateur -->
<div class="modal fade" id="modalEditUser" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:14px;overflow:hidden">
      <div class="modal-header py-2" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <h6 class="modal-title fw-bold d-flex align-items-center gap-2" style="font-size:.88rem;color:#312e81">
          <i class="bi bi-person-gear text-primary"></i>Modifier l'utilisateur
        </h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="action"  value="user_modifier">
        <input type="hidden" name="user_id" id="eu-uid">
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Nom *</label>
              <input type="text" name="nom" id="eu-nom" class="form-control form-control-sm" required>
            </div>
            <div class="col-6">
              <label class="form-label">Prénom</label>
              <input type="text" name="prenom" id="eu-prenom" class="form-control form-select-sm">
            </div>
            <div class="col-6">
              <label class="form-label">Login *</label>
              <input type="text" name="login" id="eu-login" class="form-control form-control-sm" required>
            </div>
            <div class="col-6">
              <label class="form-label">Rôle</label>
              <select name="role" id="eu-role" class="form-select form-select-sm">
                <?php foreach ($roles_list as $rl): ?>
                  <option value="<?= $rl ?>"><?= $rl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">
                Nouveau mot de passe
                <span class="text-muted" style="font-size:.7rem">— laisser vide pour ne pas changer</span>
              </label>
              <input type="text" name="mot_de_passe" id="eu-mdp" class="form-control form-control-sm" placeholder="••••••">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Form caché supprimer -->
<form method="post" id="form-del-user" style="display:none">
  <?= csrf_champ() ?>
  <input type="hidden" name="action"  value="user_supprimer">
  <input type="hidden" name="user_id" id="del-uid">
</form>

<script>
function voirMdp(uid, login) {
    document.getElementById('mdp-uid').value    = uid;
    document.getElementById('mdp-login').value  = login;
    document.getElementById('mdp-login-affiche').textContent = login;
    document.getElementById('new-mdp').value    = '';
    new bootstrap.Modal(document.getElementById('modalMdp')).show();
}
function genererMdp() {
    var alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    var octets = new Uint8Array(10);
    crypto.getRandomValues(octets);
    var mdp = '';
    for (var i = 0; i < octets.length; i++) mdp += alphabet[octets[i] % alphabet.length];
    document.getElementById('new-mdp').value = mdp;
}
function ouvrirEditUser(u) {
    document.getElementById('eu-uid').value    = u.id;
    document.getElementById('eu-nom').value    = u.nom || '';
    document.getElementById('eu-prenom').value = u.prenom || '';
    document.getElementById('eu-login').value  = u.login || '';
    document.getElementById('eu-role').value   = u.role || '';
    document.getElementById('eu-mdp').value    = '';
    new bootstrap.Modal(document.getElementById('modalEditUser')).show();
}
function supprimerUser(uid, login) {
    if (!confirm('Supprimer l\'utilisateur « ' + login + ' » ?')) return;
    document.getElementById('del-uid').value = uid;
    document.getElementById('form-del-user').submit();
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
