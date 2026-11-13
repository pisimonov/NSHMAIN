<?php defined('APP_ROOT') || exit;
page_head('Вход - выручка сети'); ?>
<div class="auth">
  <form class="box" method="post" action="index.php?action=login" autocomplete="on">
    <h1>НЕ ШКОЛА</h1>
    <p>Накопленная выручка сети. Войдите, чтобы открыть отчёт.</p>
    <?php if ($loginError): ?><div class="flash err"><?= e($loginError) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field"><label for="u">Логин</label><input id="u" type="text" name="user" required autofocus autocomplete="username" value="<?= e($_POST['user'] ?? '') ?>"></div>
    <div class="field"><label for="p">Пароль</label><input id="p" type="password" name="pass" required autocomplete="current-password"></div>
    <button class="btn primary" type="submit" style="width:100%;justify-content:center;padding:10px">Войти</button>
  </form>
</div>
<?php page_foot();
