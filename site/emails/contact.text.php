Type de demande : <?= $mode === 'owner' ? 'PROPRIÉTAIRE' : 'VISITEUR' ?>


Nom : <?= $sender ?>

E-mail : <?= $email ?>

<?php if ($mode === 'owner'): ?>
Propriété : <?= $property ?>

Région : <?= $region ?>

Type de patrimoine : <?= $property_type ?>

Activités proposées : <?= $activities ?>

<?php else: ?>
<?php if (!empty($subject)): ?>Objet : <?= $subject ?>

<?php endif ?>
<?php if (!empty($property)): ?>Propriété concernée : <?= $property ?>

<?php endif ?>
<?php endif ?>

Message :
<?= $message ?>
