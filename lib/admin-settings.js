jQuery(document).ready(function ($) {
    // Password field visibility toggle
    $(':password').focus(function () {
        $(this).get(0).type = 'text';
    }).blur(function () {
        $(this).get(0).type = 'password';
    });

    // Input validation for OSS configuration fields
    $('.form-table :input:lt(6):gt(2)').blur(function () {
        if ($(this).val().indexOf($(this).attr('placeholder').substr(0, 4)) != 0) {
            $(this).val('');
        }
    });

    // Confirmation dialogs for action links
    $('a[href*="action=clean"]').click(function () {
        return confirm(ossUploadL10n.confirmClean);
    });

    $('a[href*="action=upload"]').click(function () {
        return confirm(ossUploadL10n.confirmUpload);
    });

    $('a[href*="action=sync"]').click(function () {
        return confirm(ossUploadL10n.confirmSync);
    });

    $('a[href*="action=reset"]').click(function () {
        return confirm(ossUploadL10n.confirmReset);
    });
});
