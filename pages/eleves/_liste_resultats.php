<?php
// Fragment réutilisé par pages/eleves/liste.php en rechargement complet ET
// en réponse AJAX (partiel=1) — onglets Actifs/Désactivés + tableau +
// pagination. Ne jamais inclure ce fichier directement (variables $eleves,
// $total, $statut, $tri, $ordre, $page, $pp, $offset, $nb_pages, $peut_gerer
// attendues déjà calculées par l'appelant).
$nb_pages = max(1, (int)ceil($total / $pp));
?>
<ul class="nav nav-tabs mb-2">
  <li class="nav-item">
    <a class="nav-link <?= $statut === 'actif' ? 'active' : '' ?>" href="#" onclick="changerStatutListe('actif');return false">
      <i class="bi bi-check-circle me-1"></i>Actifs
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $statut === 'desactive' ? 'active' : '' ?>" href="#" onclick="changerStatutListe('desactive');return false">
      <i class="bi bi-slash-circle me-1"></i>Désactivés
    </a>
  </li>
  <?php if (!empty($peut_importer)): ?>
  <li class="nav-item">
    <a class="nav-link <?= $statut === 'outils' ? 'active' : '' ?>" href="#" onclick="changerStatutListe('outils');return false">
      <i class="bi bi-upc-scan me-1"></i>Import &amp; matricules
    </a>
  </li>
  <?php endif; ?>
</ul>

<?php if (($statut ?? '') === 'outils'): ?>
  <?php require __DIR__ . '/_eleves_outils.php'; ?>
<?php else: ?>

<div class="text-muted mb-1" style="font-size:.78rem"><?= $total ?> élève(s) <?= $statut === 'actif' ? 'actif(s)' : 'désactivé(s)' ?><?= $q !== '' ? ' — recherche « ' . h($q) . ' »' : '' ?></div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:34px">#</th>
          <th><?= th_tri('nom',    'Élève',    $tri, $ordre) ?></th>
          <th><?= th_tri('mat',    'Matricule',$tri, $ordre) ?></th>
          <th><?= th_tri('sexe',   'Sexe',     $tri, $ordre) ?></th>
          <th><?= th_tri('classe', 'Classe',   $tri, $ordre) ?></th>
          <th>Date naiss.</th>
          <th>Lieu naiss.</th>
          <th>NIU</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($eleves)): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-4">
              <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>
              Aucun élève trouvé.
            </td>
          </tr>
        <?php else: $no = $offset + 1; foreach ($eleves as $e): ?>
          <tr>
            <td class="text-muted" style="font-size:.72rem"><?= $no++ ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <img src="<?= url_photo_eleve((int)$e['id_eleve'], (bool)$e['a_photo'], $e['Sexe_elv']) ?>"
                     class="rounded-circle" style="width:32px;height:32px;object-fit:cover;flex-shrink:0">
                <a href="<?= APP_URL ?>/pages/eleves/voir.php?id=<?= (int)$e['id_eleve'] ?>"
                   class="fw-semibold text-decoration-none" style="font-size:.82rem">
                  <?= h(mb_strtoupper($e['Nom_elv'])) ?> <?= h($e['Prenom_elv'] ?? '') ?>
                </a>
              </div>
            </td>
            <td><span class="badge-code"><?= h($e['Mat_elv']) ?></span></td>
            <td><?= stripos($e['Sexe_elv'],'F')===0 ? '<span class="badge-f">F</span>' : '<span class="badge-m">M</span>' ?></td>
            <td style="font-size:.78rem"><?= h($e['DesignationClasses'] ?? '—') ?></td>
            <td style="font-size:.78rem;color:#6b7280"><?= date_fr($e['Date_naiss_elv']) ?></td>
            <td style="font-size:.78rem;color:#6b7280"><?= h($e['Lieu_naiss_elv'] ?: '—') ?></td>
            <td style="font-size:.78rem;color:#6b7280"><?= h($e['niu'] ?: '—') ?></td>
            <td>
              <div class="d-flex justify-content-end gap-1">
                <a href="<?= APP_URL ?>/pages/eleves/voir.php?id=<?= (int)$e['id_eleve'] ?>"
                   class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px">
                  <i class="bi bi-eye" style="font-size:.78rem"></i>
                </a>
                <?php if ($peut_gerer): ?>
                <a href="<?= APP_URL ?>/pages/eleves/form.php?id=<?= (int)$e['id_eleve'] ?>"
                   class="btn btn-sm btn-light" style="padding:3px 7px">
                  <i class="bi bi-pencil" style="font-size:.78rem"></i>
                </a>
                <?php if ($statut === 'actif'): ?>
                  <a href="<?= APP_URL ?>/pages/eleves/statut.php?id=<?= (int)$e['id_eleve'] ?>&csrf=<?= csrf_generer() ?>"
                     class="btn btn-sm" style="background:#fff3cd;color:#856404;padding:3px 7px"
                     title="Désactiver" onclick="return confirm('Désactiver cet élève ?')">
                    <i class="bi bi-toggle-on" style="font-size:.78rem"></i>
                  </a>
                <?php else: ?>
                  <a href="<?= APP_URL ?>/pages/eleves/statut.php?id=<?= (int)$e['id_eleve'] ?>&csrf=<?= csrf_generer() ?>"
                     class="btn btn-sm" style="background:#d1fae5;color:#065f46;padding:3px 7px"
                     title="Réactiver" onclick="return confirm('Réactiver cet élève ?')">
                    <i class="bi bi-toggle-off" style="font-size:.78rem"></i>
                  </a>
                <?php endif; ?>
                <?php if (role_connecte() === 'DIRECTEUR'): ?>
                  <a href="<?= APP_URL ?>/pages/eleves/supprimer.php?id=<?= (int)$e['id_eleve'] ?>&csrf=<?= csrf_generer() ?>"
                     class="btn btn-sm btn-light text-danger" style="padding:3px 7px" title="Supprimer définitivement"
                     onclick="return confirm('Supprimer définitivement <?= h(addslashes(mb_strtoupper($e['Nom_elv']))) ?> ? Cette action est irréversible (fiche, parents, informations complémentaires, pièces jointes).')">
                    <i class="bi bi-trash" style="font-size:.78rem"></i>
                  </a>
                <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($nb_pages > 1): ?>
  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <div class="text-muted" style="font-size:.75rem">
      Page <?= $page ?> / <?= $nb_pages ?> &nbsp;·&nbsp; <?= $total ?> élève(s)
    </div>
    <nav><ul class="pagination pagination-sm justify-content-center mb-0">
      <?php for ($p = 1; $p <= $nb_pages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="#" onclick="allerPageListe(<?= $p ?>);return false"><?= $p ?></a>
        </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
<?php endif; ?>

<?php endif; // fin onglet liste vs. onglet « outils » ?>
