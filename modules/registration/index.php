<?php
/**
 * mobiCMS (https://mobicms.org/)
 * This file is part of mobiCMS Content Management System.
 *
 * @license     https://opensource.org/licenses/GPL-3.0 GPL-3.0 (see the LICENSE.md file)
 * @link        http://mobicms.org mobiCMS Project
 * @copyright   Copyright (C) mobiCMS Community
 */

defined('MOBICMS') or die('Error: restricted access');

/** @var Psr\Container\ContainerInterface $container */
$container = App::getContainer();

/** @var Psr\Http\Message\ServerRequestInterface $request */
$request = $container->get(Psr\Http\Message\ServerRequestInterface::class);
$postParams = $request->getParsedBody();

/** @var Mobicms\Api\UserInterface $systemUser */
$systemUser = $container->get(Mobicms\Api\UserInterface::class);

/** @var Mobicms\Api\ToolsInterface $tools */
$tools = $container->get(Mobicms\Api\ToolsInterface::class);

/** @var Mobicms\Api\ConfigInterface $config */
$config = $container->get(Mobicms\Api\ConfigInterface::class);

/** @var Zend\I18n\Translator\Translator $translator */
$translator = $container->get(Zend\I18n\Translator\Translator::class);
$translator->addTranslationFilePattern('gettext', __DIR__ . '/locale', '/%s/default.mo');

/** @var League\Plates\Engine $view */
$view = $container->get(League\Plates\Engine::class);

$pageTitle = _t('Registration');
ob_start();

// Если регистрация закрыта, выводим предупреждение
if (!$config->mod_reg || $systemUser->isValid()) {
    echo $view->render('system::app/legacy', [
        'title'   => $pageTitle,
        'content' => '<p>' . _t('Registration is temporarily closed') . '</p>',
    ]);
    exit;
}

$captcha = isset($postParams['captcha']) ? trim($postParams['captcha']) : null;
$reg_nick = isset($postParams['nick']) ? trim($postParams['nick']) : '';
$lat_nick = $tools->rusLat($reg_nick);
$reg_pass = isset($postParams['password']) ? trim($postParams['password']) : '';
$reg_name = isset($postParams['imname']) ? trim($postParams['imname']) : '';
$reg_about = isset($postParams['about']) ? trim($postParams['about']) : '';
$reg_sex = isset($postParams['sex']) ? trim($postParams['sex']) : '';

echo '<div class="phdr"><b>' . _t('Registration') . '</b></div>';

if (isset($postParams['submit'])) {
    /** @var PDO $db */
    $db = $container->get(PDO::class);

    // Принимаем переменные
    $error = [];

    // Проверка Логина
    if (empty($reg_nick)) {
        $error['login'][] = _t('You have not entered Nickname');
    } elseif (mb_strlen($reg_nick) < 2 || mb_strlen($reg_nick) > 20) {
        $error['login'][] = _t('Nickname wrong length');
    }

    if (preg_match('/[^\da-z\-\@\*\(\)\?\!\~\_\=\[\]]+/', $lat_nick)) {
        $error['login'][] = _t('Invalid characters');
    }

    // Проверка пароля
    if (empty($reg_pass)) {
        $error['password'][] = _t('You have not entered password');
    } elseif (mb_strlen($reg_pass) < 3) {
        $error['password'][] = _t('Invalid length');
    }

    // Проверка пола
    if ($reg_sex != 'm' && $reg_sex != 'zh') {
        $error['sex'] = _t('You have not selected genger');
    }

    // Проверка кода CAPTCHA
    if (!$captcha
        || !isset($_SESSION['code'])
        || mb_strlen($captcha) < 3
        || mb_strtolower($captcha) != mb_strtolower($_SESSION['code'])
    ) {
        $error['captcha'] = _t('The security code is not correct');
    }

    unset($_SESSION['code']);

    // Проверка переменных
    if (empty($error)) {
        $pass = md5(md5($reg_pass));
        $reg_name = htmlspecialchars(mb_substr($reg_name, 0, 50));
        $reg_about = htmlspecialchars(mb_substr($reg_about, 0, 1000));
        // Проверка, занят ли ник
        $stmt = $db->prepare('SELECT * FROM `users` WHERE `name_lat` = ?');
        $stmt->execute([$lat_nick]);

        if ($stmt->rowCount()) {
            $error['login'][] = _t('Selected Nickname is already in use');
        }
    }

    if (empty($error)) {
        $preg = $config->mod_reg > 1 ? 1 : 0;
        $db->prepare('
          INSERT INTO `users` SET
          `name` = ?,
          `name_lat` = ?,
          `password` = ?,
          `imname` = ?,
          `about` = ?,
          `sex` = ?,
          `rights` = 0,
          `ip` = ?,
          `ip_via_proxy` = ?,
          `browser` = ?,
          `datereg` = ?,
          `lastdate` = ?,
          `sestime` = ?,
          `preg` = ?,
          `set_user` = \'\',
          `set_forum` = \'\',
          `set_mail` = \'\',
          `smileys` = \'\'
        ')->execute([
            $reg_nick,
            $lat_nick,
            $pass,
            $reg_name,
            $reg_about,
            $reg_sex,
            $request->getAttribute('ip'),
            $request->getAttribute('ip_via_proxy'),
            $request->getAttribute('user_agent'),
            time(),
            time(),
            time(),
            $preg,
        ]);

        $usid = $db->lastInsertId();

        echo '<div class="menu"><p><h3>' . _t('Your registratiton data') . '</h3>'
            . _t('Your ID') . ': <b>' . $usid . '</b><br>'
            . _t('Your Username') . ': <b>' . $reg_nick . '</b><br>'
            . _t('Your Password') . ': <b>' . $reg_pass . '</b></p>';

        if ($config->mod_reg == 1) {
            echo '<p><span class="red"><b>' . _t('Please, wait until a moderator approves your registration') . '</b></span></p>';
        } else {
            $_SESSION['uid'] = $usid;
            $_SESSION['ups'] = md5(md5($reg_pass));
            echo '<p><a href="' . $config->homeurl . '">' . _t('Enter') . '</a></p>';
        }

        echo '</div>';

        echo $view->render('system::app/legacy', [
            'title'   => $pageTitle,
            'content' => ob_get_clean(),
        ]);
        exit;
    }
}

// Форма регистрации
if ($config->mod_reg == 1) {
    echo '<div class="rmenu"><p>' . _t('You can get authorized on the site after confirmation of your registration.') . '</p></div>';
}

$captcha = new Batumibiz\Captcha\Captcha;
$code = $captcha->generateCode();
$_SESSION['code'] = $code;

echo '<form action="index.php" method="post"><div class="gmenu">' .
    '<p><h3>' . _t('Choose Nickname') . '</h3>' .
    (isset($error['login']) ? '<span class="red"><small>' . implode('<br />',
            $error['login']) . '</small></span><br />' : '') .
    '<input type="text" name="nick" maxlength="15" value="' . htmlspecialchars($reg_nick) . '"' . (isset($error['login']) ? ' style="background-color: #FFCCCC"' : '') . '/><br />' .
    '<small>' . _t('Min. 2, Max. 20 characters.<br />Allowed letters of the russian and latin alphabets, numbers and symbols - = @ ! ? ~ _ ( ) [ ] . * (Except zero)') . '</small></p>' .
    '<p><h3>' . _t('Assign a password') . '</h3>' .
    (isset($error['password']) ? '<span class="red"><small>' . implode('<br />',
            $error['password']) . '</small></span><br />' : '') .
    '<input type="text" name="password" maxlength="20" value="' . htmlspecialchars($reg_pass) . '"' . (isset($error['password']) ? ' style="background-color: #FFCCCC"' : '') . '/><br>' .
    '<small>' . _t('Min. 3 characters') . '</small></p>' .
    '<p><h3>' . _t('Select Gender') . '</h3>' .
    (isset($error['sex']) ? '<span class="red"><small>' . $error['sex'] . '</small></span><br />' : '') .
    '<select name="sex"' . (isset($error['sex']) ? ' style="background-color: #FFCCCC"' : '') . '>' .
    '<option value="?">-?-</option>' .
    '<option value="m"' . ($reg_sex == 'm' ? ' selected="selected"' : '') . '>' . _t('Man') . '</option>' .
    '<option value="zh"' . ($reg_sex == 'zh' ? ' selected="selected"' : '') . '>' . _t('Woman') . '</option>' .
    '</select></p></div>' .
    '<div class="menu">' .
    '<p><h3>' . _t('Your name') . '</h3>' .
    '<input type="text" name="imname" maxlength="30" value="' . htmlspecialchars($reg_name) . '" /><br />' .
    '<small>' . _t('Max. 50 characters') . '</small></p>' .
    '<p><h3>' . _t('Tell us a little about yourself') . '</h3>' .
    '<textarea rows="3" name="about">' . htmlspecialchars($reg_about) . '</textarea><br />' .
    '<small>' . _t('Max. 1000 characters') . '</small></p></div>' .
    '<div class="gmenu"><p>' .
    '<h3>' . _t('Verification code') . '</h3>' .
    '<img alt="' . _t('Verification code') . '" width="' . $captcha->width . '" height="' . $captcha->height . '" src="' . $captcha->generateImage($code) . '"/><br>' .
    (isset($error['captcha']) ? '<span class="red"><small>' . $error['captcha'] . '</small></span><br />' : '') .
    '<input type="text" size="5" maxlength="5"  name="captcha" ' . (isset($error['captcha']) ? ' style="background-color: #FFCCCC"' : '') . '/><br />' .
    '<small>' . _t('If you cannot see the image code, enable graphics in your browser and refresh this page') . '</small></p>' .
    '<p><input type="submit" name="submit" value="' . _t('Registration') . '"/></p></div></form>' .
    '<div class="phdr"><small>' . _t('Please, do not register names like 111, shhhh, uuuu, etc. They will be deleted. <br /> Also all the profiles registered via proxy servers will be deleted') . '</small></div>';

echo $view->render('system::app/legacy', [
    'title'   => $pageTitle,
    'content' => ob_get_clean(),
]);
