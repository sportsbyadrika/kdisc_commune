<?php
/** @var App\Core\Template $this */
$this->layout('layouts/site');
?>
<?= $this->component('page-hero', ['eyebrow' => 'Legal', 'title' => 'Terms of use', 'subtitle' => 'Draft — cancellation, refund and handover policies are pending confirmation by K-DISC.', 'breadcrumb' => [['Home', url('home')], ['Terms of use']]]) ?>
<section class="section">
    <div class="container-page prose-page max-w-3xl">
        <h2 class="!mt-0">Bookings</h2>
        <p>Online booking requests are confirmed after approval by the Centre Manager and payment. Bookings of up to six months require an advance payment; longer tenures require a security deposit.</p>
        <h2>Conduct</h2>
        <p>Members are expected to respect shared spaces, keep noise to a minimum in enclosed rooms and follow the centre's safety instructions.</p>
        <h2>Payments</h2>
        <p>All prices are exclusive of GST. GST invoices and receipts are issued for every verified payment.</p>
    </div>
</section>
