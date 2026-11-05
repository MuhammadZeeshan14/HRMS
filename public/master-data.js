(() => {
    document.querySelectorAll('[data-edit-record]').forEach(button => {
        button.addEventListener('click', () => document.getElementById(button.dataset.editRecord).showModal());
    });
    document.querySelectorAll('[data-close-edit]').forEach(button => {
        button.addEventListener('click', () => button.closest('dialog').close());
    });
    document.querySelector('[data-reopen-edit]')?.showModal();
})();
