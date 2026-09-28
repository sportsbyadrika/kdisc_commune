<?php
/**
 * Contact page with validated enquiry form (CSRF + old input + inline errors).
 *
 * @var App\Core\Template $this
 */
$this->layout('layouts/site', ['hideFlash' => true]);
$org = (array) config('app.org');
?>
<?= $this->component('page-hero', [
    'eyebrow' => 'Contact',
    'title' => 'Talk to the Commune team',
    'subtitle' => 'Questions about seats, pricing or a visit? Send us a message or drop by the centre.',
    'breadcrumb' => [['Home', url('home')], ['Contact']],
]) ?>
<section class="section">
    <div class="container-page grid grid-cols-1 gap-10 lg:grid-cols-[1.4fr_1fr]">
        <div class="card card-body sm:!p-8">
            <h2 class="text-2xl font-bold">Send a message</h2>
            <p class="mt-1 text-sm text-muted">We reply within one working day.</p>
            <?= $this->partial('partials/flash', ['class' => 'mt-6', 'hideErrorSummary' => true]) ?>
            <form method="post" action="<?= e(url('contact.submit')) ?>" class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2" novalidate>
                <?= csrf_field() ?>
                <?= $this->component('input', ['name' => 'name', 'label' => 'Your name', 'required' => true, 'autocomplete' => 'name', 'icon' => 'user']) ?>
                <?= $this->component('input', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'icon' => 'mail']) ?>
                <?= $this->component('input', ['name' => 'mobile', 'label' => 'Mobile', 'type' => 'tel', 'placeholder' => '+91 98xxxxxxxx', 'autocomplete' => 'tel', 'icon' => 'phone']) ?>
                <?= $this->component('select', ['name' => 'topic', 'label' => 'Topic', 'required' => true, 'placeholder' => 'Choose a topic…', 'options' => [
                    'booking' => 'Booking a seat', 'pricing' => 'Pricing & payments', 'visit' => 'Schedule a visit', 'other' => 'Something else',
                ]]) ?>
                <?= $this->component('textarea', ['name' => 'message', 'label' => 'Message', 'required' => true, 'rows' => 5, 'class' => 'sm:col-span-2', 'placeholder' => 'How can we help?']) ?>
                <div class="sm:col-span-2">
                    <?= $this->component('button', ['label' => 'Send message', 'type' => 'submit', 'variant' => 'primary', 'size' => 'lg', 'iconRight' => 'arrow-right']) ?>
                </div>
            </form>
        </div>
        <aside class="space-y-4">
            <?php foreach ([
                ['map-pin', 'Address', $org['address'] ?? ''],
                ['phone', 'Phone', $org['phone'] ?? ''],
                ['mail', 'Email', $org['email'] ?? ''],
                ['clock', 'Opening hours', $org['hours'] ?? ''],
            ] as [$ic, $label, $value]): ?>
                <div class="card flex gap-4 p-5">
                    <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-50 text-accent-600"><?= icon($ic, 'size-5') ?></span>
                    <div class="min-w-0"><p class="text-sm font-medium text-muted"><?= e($label) ?></p><p class="mt-0.5 font-semibold break-words"><?= e($value) ?></p></div>
                </div>
            <?php endforeach ?>
            <a href="<?= e($org['map_url'] ?? '#') ?>" target="_blank" rel="noopener" class="btn btn-dark w-full"><?= icon('map', 'size-4') ?> Get directions</a>
        </aside>
    </div>
</section>
