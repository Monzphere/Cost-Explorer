<?php


/**
 * @var CView $this
 */
?>

<script type="text/javascript">
function openPricingConfigDialog() {
    overlayDialogue({
        "title": "<?= _('Configure Pricing') ?>",
        "content": getPricingConfigForm(),
        "class": "costexplorer-pricing-modal",
        "buttons": [
            {
                "title": "<?= _('Save') ?>",
                "class": "dialogue-widget-save",
                "keepOpen": true,
                "isSubmit": true,
                "action": "savePricingConfig();"
            },
            {
                "title": "<?= _('Cancel') ?>",
                "class": "btn-alt",
                "cancel": true,
                "action": "overlayDialogueDestroy(this);"
            }
        ]
    }, this);
    
    // Add specific class after modal is created
    setTimeout(function() {
        const modal = document.querySelector('.overlay-dialogue:last-of-type');
        if (modal) {
            modal.classList.add('costexplorer-pricing-modal');
        }
    }, 10);
}

function getPricingConfigForm() {
    var pricing = <?= json_encode($data['pricing']) ?>;
    return '<form id="pricing-form">' +
        '<div class="form-grid">' +
            '<label for="per_cpu_core"><?= _('CPU per core/hour ($):') ?></label>' +
            '<input type="number" id="per_cpu_core" name="per_cpu_core" value="' + pricing.per_cpu_core + '" step="0.00001">' +
            '<label for="per_memory_gb"><?= _('Memory per GB/hour ($):') ?></label>' +
            '<input type="number" id="per_memory_gb" name="per_memory_gb" value="' + pricing.per_memory_gb + '" step="0.000001">' +
        '</div>' +
        '</form>';
}

function savePricingConfig() {
    var form = document.getElementById("pricing-form");
    var formData = new FormData(form);
    
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "?action=costexplorer.pricing.update", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            location.reload();
        } else {
            alert("<?= _('Error saving pricing configuration') ?>");
        }
    };
    
    var params = "per_cpu_core=" + formData.get("per_cpu_core") + 
                 "&per_memory_gb=" + formData.get("per_memory_gb");
    xhr.send(params);
}
</script>
