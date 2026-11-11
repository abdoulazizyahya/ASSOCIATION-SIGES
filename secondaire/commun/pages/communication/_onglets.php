<?php
// Onglets du module « Envoyer aux parents » (10/11/2026). $onglet_comm = clé active.
$__ong = [
    'envoyer'  => ['Envoyer', 'send', 'envoyer.php'],
    'modeles'  => ['Modèles & automatiques', 'card-text', 'modeles.php'],
    'journal'  => ['Journal', 'journal-text', 'journal.php'],
    'contacts' => ['Contacts des parents', 'telephone', 'contacts_parents.php'],
];
if (function_exists('sms_gw_peut_configurer') && sms_gw_peut_configurer()) $__ong['passerelle'] = ['Passerelle SMS', 'phone', 'passerelle.php'];
?>
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach ($__ong as $__k => [$__l, $__i, $__u]): ?>
  <li class="nav-item"><a class="nav-link <?= ($onglet_comm ?? '') === $__k ? 'active' : '' ?>" href="<?= APP_URL ?>/secondaire/commun/pages/communication/<?= $__u ?>"><i class="bi bi-<?= $__i ?> me-1"></i><?= h($__l) ?></a></li>
  <?php endforeach; ?>
</ul>
