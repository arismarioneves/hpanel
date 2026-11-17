<?php

use HPanel\View;

/** @var string $base @var string $page @var string $title @var string $bodyFile @var string $bootJson @var bool $connected @var bool $demo @var bool $viaExtension */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="application-name" content="HPanel">
    <base href="<?= View::e($base) ?>">
    <title><?= View::e($title) ?> · HPanel</title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="icon.png">
    <link rel="apple-touch-icon" href="icon.png">
    <link rel="preload" href="<?= View::e(View::asset('fonts/inter-latin-var.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= View::e(View::asset('css/app.css')) ?>">
    <script src="<?= View::e(View::asset('js/theme-init.js')) ?>"></script>
    <script type="module" src="<?= View::e(View::asset('js/pages/' . $page . '.js')) ?>"></script>
</head>
<body data-page="<?= View::e($page) ?>"<?= $viaExtension ? ' data-via="extension"' : '' ?>>
    <a class="skip-link" href="#main">Pular para o conteúdo</a>
    <?php require __DIR__ . '/header.php'; ?>
    <?php if ($demo): ?>
        <div class="demo-bar" role="status">
            <div class="container demo-bar-inner">
                <span><?= View::icon('info') ?>Você está vendo dados fictícios.</span>
                <span class="row">
                    <a href="connect" class="btn btn-primary btn-sm">Conectar sua conta</a>
                    <button type="button" class="btn btn-secondary btn-sm" data-action="logout">Sair do demo</button>
                </span>
            </div>
        </div>
    <?php endif; ?>
    <main id="main" class="container" tabindex="-1">
        <?php require $bodyFile; ?>
    </main>
    <div id="toasts" class="toasts"></div>
    <script type="application/json" id="boot"><?= $bootJson ?></script>
</body>
</html>
