<?php
/** @var App\Core\Template $this */
$this->layout('layouts/site');
?>
<?= $this->component('page-hero', ['eyebrow' => 'Legal', 'title' => 'Privacy policy', 'subtitle' => 'Draft — to be finalised with K-DISC under the Digital Personal Data Protection Act, 2023.', 'breadcrumb' => [['Home', url('home')], ['Privacy policy']]]) ?>
<section class="section">
    <div class="container-page prose-page max-w-3xl">
        <h2 class="!mt-0">What we collect</h2>
        <p>To register you as a Commune visitor we collect your name, contact details and KYC information, including your Aadhaar number (and, for institutions, PAN, GSTIN and TAN), with your explicit consent.</p>
        <h2>How we protect it</h2>
        <ul>
            <li>Aadhaar numbers are encrypted at rest; only the last four digits are ever displayed.</li>
            <li>Uploaded documents are stored outside the public website and are visible only to authorised staff. Every access is logged.</li>
            <li>We never sell or share your data with third parties for marketing.</li>
        </ul>
        <h2>Your rights</h2>
        <p>You may request access to, correction of, or deletion of your personal data (subject to legal retention of financial records) by contacting the centre.</p>
    </div>
</section>
