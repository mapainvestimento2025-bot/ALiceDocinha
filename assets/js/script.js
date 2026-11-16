function buscarConteudo() {
    const campo = document.getElementById('busca');

    if (!campo) return;

    const pesquisa = campo.value.toLowerCase();

    document.querySelectorAll('.card').forEach(card => {
        const nome = card.dataset.nome || '';

        card.style.display = nome.includes(pesquisa)
            ? ''
            : 'none';
    });
}
