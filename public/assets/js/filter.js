document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-filter-target]').forEach(function (input) {
        var target = document.querySelector(input.dataset.filterTarget);
        if (!target) {
            return;
        }
        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            target.querySelectorAll('[data-name]').forEach(function (el) {
                el.style.display = el.dataset.name.indexOf(term) !== -1 ? '' : 'none';
            });
        });
    });

    document.querySelectorAll('[data-confirm]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.confirm(link.dataset.confirm)) {
                document.getElementById(link.dataset.formId).submit();
            }
        });
    });
});
