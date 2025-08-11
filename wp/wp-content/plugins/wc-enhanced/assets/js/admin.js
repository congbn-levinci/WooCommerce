jQuery(document).ready(function ($) {
    // Get initial tier count for indexing
    var tierIndex = $('.tier-row').length;

    // Handle adding a new tier row
    $('#add-tier').on('click', function (e) {
        e.preventDefault();
        var newRow = '<div class="tier-row">' +
            '<label>' + wcEnhanced.packSizeLabel + '</label>' +
            '<input type="number" name="pack_tiers[' + tierIndex + '][quantity]" value="" min="2" />' +
            '<label>' + wcEnhanced.packPriceLabel + '</label>' +
            '<input type="number" step="0.01" name="pack_tiers[' + tierIndex + '][price]" value="" min="0" />' +
            '<button type="button" class="remove-tier button">' + wcEnhanced.removeLabel + '</button>' +
            '</div>';
        $('#add-tier').before(newRow);

        // Scroll to the bottom of the container
        var container = $('#pack-pricing-tiers');
        container.animate({scrollTop: container[0].scrollHeight}, 300);

        tierIndex++;
    });

    // Handle removing a tier row
    $('#pack-pricing-tiers').on('click', '.remove-tier', function (e) {
        e.preventDefault();
        $(this).closest('.tier-row').remove();
    });
});
