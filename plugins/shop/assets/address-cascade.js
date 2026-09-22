/* Mainland shipping regions are embedded from the same tree used by server validation. */
(function () {
    'use strict';

    var form = document.querySelector('[data-testid="shop-checkout-form"]');
    if (!form) return;
    var province = form.elements.province;
    var city = form.elements.city;
    var district = form.elements.district;
    var submit = form.querySelector('[data-testid="shop-checkout-submit"]');
    var error = form.querySelector('[data-testid="shop-checkout-region-error"]');
    var source = document.getElementById('shop-mainland-regions');
    if (!province || !city || !district || !submit) return;

    var tree;
    var placeholders = [province, city, district].map(function (select) {
        return select.options[0] ? select.options[0].textContent : '';
    });

    function validNodes(nodes, depth) {
        if (!Array.isArray(nodes) || nodes.length === 0) return false;
        var names = Object.create(null);
        var codes = Object.create(null);
        return nodes.every(function (node) {
            if (!node || typeof node.n !== 'string' || node.n.trim() === ''
                || typeof node.c !== 'string' || !/^[0-9]{2,12}$/.test(node.c)
                || names[node.n] || codes[node.c]) return false;
            names[node.n] = true;
            codes[node.c] = true;
            return depth === 2 || validNodes(node.ch, depth + 1);
        });
    }

    function failClosed() {
        province.disabled = true;
        city.disabled = true;
        district.disabled = true;
        submit.disabled = true;
        if (error) error.hidden = false;
    }

    try {
        tree = JSON.parse(source ? source.textContent : 'null');
        if (!validNodes(tree, 0)) throw new Error('Invalid shipping region tree');
    } catch (ignored) {
        failClosed();
        return;
    }

    function find(nodes, name) {
        return (nodes || []).find(function (node) { return node.n === name; }) || null;
    }

    function populate(select, nodes, placeholder, selected) {
        select.textContent = '';
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder;
        select.appendChild(empty);
        nodes.forEach(function (node) {
            var option = document.createElement('option');
            option.value = node.n;
            option.textContent = node.n;
            select.appendChild(option);
        });
        select.disabled = nodes.length === 0;
        select.value = find(nodes, selected) ? selected : (nodes.length === 1 ? nodes[0].n : '');
    }

    function updateSubmit() {
        var selectedProvince = find(tree, province.value);
        var selectedCity = find(selectedProvince && selectedProvince.ch, city.value);
        var selectedDistrict = find(selectedCity && selectedCity.ch, district.value);
        submit.disabled = !selectedDistrict;
    }

    function refreshDistrict(selected) {
        var selectedProvince = find(tree, province.value);
        var selectedCity = find(selectedProvince && selectedProvince.ch, city.value);
        populate(district, selectedCity ? selectedCity.ch : [], placeholders[2], selected);
        updateSubmit();
    }

    function refreshCity(selectedCity, selectedDistrict) {
        var selectedProvince = find(tree, province.value);
        populate(city, selectedProvince ? selectedProvince.ch : [], placeholders[1], selectedCity);
        refreshDistrict(selectedDistrict);
    }

    function notifyShipping() {
        // Existing shipping listeners must see cleared lower levels, not the previous address.
        district.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function restore() {
        var previousProvince = province.value;
        var previousCity = city.value;
        var previousDistrict = district.value;
        populate(province, tree, placeholders[0], previousProvince);
        refreshCity(previousCity, previousDistrict);
        notifyShipping();
    }

    province.addEventListener('change', function () {
        refreshCity('', '');
        notifyShipping();
    });
    city.addEventListener('change', function () {
        refreshDistrict('');
        notifyShipping();
    });
    district.addEventListener('change', updateSubmit);
    form.addEventListener('submit', function (event) {
        updateSubmit();
        if (submit.disabled) event.preventDefault();
    });
    window.addEventListener('pageshow', restore);
    restore();
}());
