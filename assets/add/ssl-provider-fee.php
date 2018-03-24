<?php
/**
 * /assets/add/ssl-provider-fee.php
 *
 * This file is part of DomainMOD, an open source domain and internet asset manager.
 * Copyright (c) 2010-2018 Greg Chetcuti <greg@chetcuti.com>
 *
 * Project: http://domainmod.org   Author: http://chetcuti.com
 *
 * DomainMOD is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * DomainMOD is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with DomainMOD. If not, see
 * http://www.gnu.org/licenses/.
 *
 */
?>
<?php
require_once __DIR__ . '/../../_includes/start-session.inc.php';
require_once __DIR__ . '/../../_includes/init.inc.php';
require_once DIR_INC . '/config.inc.php';
require_once DIR_INC . '/software.inc.php';
require_once DIR_ROOT . '/vendor/autoload.php';

$assets = new DomainMOD\Assets();
$conversion = new DomainMOD\Conversion();
$deeb = DomainMOD\Database::getInstance();
$form = new DomainMOD\Form();
$layout = new DomainMOD\Layout();
$log = new DomainMOD\Log('/assets/add/ssl-provider-fee.php');
$system = new DomainMOD\System();
$time = new DomainMOD\Time();

require_once DIR_INC . '/head.inc.php';
require_once DIR_INC . '/debug.inc.php';
require_once DIR_INC . '/settings/assets-add-ssl-provider-fee.inc.php';

$system->authCheck();
$system->readOnlyCheck($_SERVER['HTTP_REFERER']);
$pdo = $deeb->cnxx;

$sslpid = $_REQUEST['sslpid'];
$type_id = $_GET['type_id'];
if ($type_id != '') {
    $new_type_id = $type_id;
} else {
    $new_type_id = $_POST['new_type_id'];
}
$new_initial_fee = $_POST['new_initial_fee'];
$new_renewal_fee = $_POST['new_renewal_fee'];
$new_misc_fee = $_POST['new_misc_fee'];
$new_currency = $_POST['new_currency'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if ($new_type_id == '' || $new_type_id == '0' || $new_initial_fee == '' || $new_renewal_fee == '') {

        if ($new_type_id == '' || $new_type_id == '0') $_SESSION['s_message_danger'] .= "Choose the SSL Type<BR>";
        if ($new_initial_fee == '') $_SESSION['s_message_danger'] .= "Enter the initial fee<BR>";
        if ($new_renewal_fee == '') $_SESSION['s_message_danger'] .= "Enter the renewal fee<BR>";

    } else {

        $timestamp = $time->stamp();

        $stmt = $pdo->prepare("
            SELECT id
            FROM ssl_fees
            WHERE ssl_provider_id = :sslpid
              AND type_id = :new_type_id
            LIMIT 1");
        $stmt->bindValue('sslpid', $sslpid, PDO::PARAM_INT);
        $stmt->bindValue('new_type_id', $new_type_id, PDO::PARAM_INT);
        $stmt->execute();
        $fee_id = $stmt->fetchColumn();

        if ($fee_id) {

            $_SESSION['s_message_danger'] .= 'A fee for this SSL Type already exists [<a href=\'' . WEB_ROOT .
                '/assets/edit/ssl-provider-fee.php?sslpid=' . urlencode($sslpid) . '&fee_id=' . urlencode($fee_id) .
                '\'>edit fee</a>]<BR>';

        } else {

            try {

                $pdo->beginTransaction();

                $currency = new DomainMOD\Currency();
                $currency_id = $currency->getCurrencyId($new_currency);

                $stmt = $pdo->prepare("
                    INSERT INTO ssl_fees
                    (ssl_provider_id, type_id, initial_fee, renewal_fee, misc_fee, currency_id, insert_time)
                    VALUES
                    (:sslpid, :new_type_id, :new_initial_fee, :new_renewal_fee, :new_misc_fee, :currency_id, :timestamp)");
                $stmt->bindValue('sslpid', $sslpid, PDO::PARAM_INT);
                $stmt->bindValue('new_type_id', $new_type_id, PDO::PARAM_INT);
                $stmt->bindValue('new_initial_fee', strval($new_initial_fee), PDO::PARAM_STR);
                $stmt->bindValue('new_renewal_fee', strval($new_renewal_fee), PDO::PARAM_STR);
                $stmt->bindValue('new_misc_fee', strval($new_misc_fee), PDO::PARAM_STR);
                $stmt->bindValue('currency_id', $currency_id, PDO::PARAM_INT);
                $stmt->bindValue('timestamp', $timestamp, PDO::PARAM_STR);
                $stmt->execute();

                $new_fee_id = $pdo->lastInsertId('id');

                $stmt = $pdo->prepare("
                    UPDATE ssl_certs
                    SET fee_id = :new_fee_id,
                        update_time = :timestamp
                    WHERE ssl_provider_id = :sslpid
                      AND type_id = :new_type_id");
                $stmt->bindValue('new_fee_id', $new_fee_id, PDO::PARAM_INT);
                $stmt->bindValue('timestamp', $timestamp, PDO::PARAM_STR);
                $stmt->bindValue('sslpid', $sslpid, PDO::PARAM_INT);
                $stmt->bindValue('new_type_id', $new_type_id, PDO::PARAM_INT);
                $stmt->execute();

                $temp_type = $assets->getSslType($new_type_id);

                $stmt = $pdo->prepare("
                    UPDATE ssl_certs sslc
                    JOIN ssl_fees sslf ON sslc.fee_id = sslf.id
                    SET sslc.total_cost = sslf.renewal_fee + sslf.misc_fee
                    WHERE sslc.fee_id = :new_fee_id");
                $stmt->bindValue('new_fee_id', $new_fee_id, PDO::PARAM_INT);
                $stmt->execute();

                $queryB = new DomainMOD\QueryBuild();

                $sql = $queryB->missingFees('ssl_certs');
                $_SESSION['s_missing_ssl_fees'] = $system->checkForRows($sql);

                $conversion->updateRates($_SESSION['s_default_currency'], $_SESSION['s_user_id']);

                $pdo->commit();

                $_SESSION['s_message_success'] .= "The fee for " . $temp_type . " has been added<BR>";

                header("Location: ../ssl-provider-fees.php?sslpid=" . urlencode($sslpid));
                exit;

            } catch (Exception $e) {

                $pdo->rollback();

                $log_message = 'Unable to add SSL provider fee';
                $log_extra = array('Error' => $e);
                $log->error($log_message, $log_extra);

                $_SESSION['s_message_danger'] .= $log_message . '<BR>';

                throw $e;

            }

        }

    }

}
?>
<?php require_once DIR_INC . '/doctype.inc.php'; ?>
<html>
<head>
    <title><?php echo $system->pageTitle($page_title); ?></title>
    <?php require_once DIR_INC . '/layout/head-tags.inc.php'; ?>
</head>
<body class="hold-transition skin-red sidebar-mini">
<?php require_once DIR_INC . '/layout/header.inc.php'; ?>
<a href="../ssl-provider-fees.php?sslpid=<?php echo urlencode($sslpid); ?>"><?php echo $layout->showButton('button', 'Back to SSL Provider Fees'); ?></a><BR><BR>
<?php
echo $form->showFormTop('');
?>
<strong>SSL Provider</strong><BR>
<?php echo $assets->getSslProvider($sslpid); ?><BR><BR><?php

echo $form->showDropdownTop('new_type_id', 'SSL Type', '', '1', '');
echo $form->showDropdownOption('', 'Choose an SSL Type', '');
$result = $pdo->query("
    SELECT id, type
    FROM ssl_cert_types
    ORDER BY `type`")->fetchAll();

foreach ($result as $row) {

    echo $form->showDropdownOption($row->id, $row->type, isset($new_type_id) ? $new_type_id : $_SESSION['s_default_ssl_type']);

}
echo $form->showDropdownBottom('');

echo $form->showInputText('new_initial_fee', 'Initial Fee', '', $new_initial_fee, '10', '', '1', '', '');
echo $form->showInputText('new_renewal_fee', 'Renewal Fee', '', $new_renewal_fee, '10', '', '1', '', '');
echo $form->showInputText('new_misc_fee', 'Misc Fee', '', $new_misc_fee, '10', '', '', '', '');

echo $form->showDropdownTop('new_currency', 'Currency', '', '', '');
$result = $pdo->query("
    SELECT id, currency, `name`, symbol
    FROM currencies
    ORDER BY `name`")->fetchAll();

foreach ($result as $row) {

    echo $form->showDropdownOption($row->currency, $row->name . ' (' . $row->currency . ')',
        isset($new_currency) ? $new_currency : $_SESSION['s_default_currency']);

}
echo $form->showDropdownBottom('');


echo $form->showSubmitButton('Add Fee', '', '');
echo $form->showInputHidden('sslpid', $sslpid);
echo $form->showFormBottom('');
?>
<?php require_once DIR_INC . '/layout/footer.inc.php'; ?>
</body>
</html>
