<header class="ALice Docinha-header">
    <div class="header-content">

        <a href="index.php" class="logo">
            ALice Docinha
        </a>

        <nav class="ALice Docinha-nav">
            <a href="index.php">Início</a>
            <a href="index.php?categoria=Anime">Animes</a>
            <a href="index.php?categoria=Filme">Filmes</a>
            <a href="index.php?categoria=Série">Séries</a>
        </nav>

    </div>
</header>

<style>
.animeflix-header {
    position: sticky;
    top: 0;
    z-index: 999;
    width: 100%;
    box-sizing: border-box;
    background: rgba(255, 250, 253, .96);
    border-bottom: 1px solid #f1dce5;
    box-shadow: 0 4px 18px rgba(125, 54, 85, .07);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}

.header-content {
    width: min(1400px, 90%);
    min-height: 76px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 25px;
}

.animeflix-header .logo {
    color: #c83d73;
    font-size: 25px;
    font-weight: 900;
    letter-spacing: 1.5px;
    text-decoration: none;
    white-space: nowrap;
    transition: .25s ease;
}

.animeflix-header .logo:hover {
    color: #a92d60;
    transform: scale(1.03);
}

.animeflix-nav {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.animeflix-nav a {
    position: relative;
    padding: 11px 15px;
    border-radius: 10px;
    color: #66535d;
    font-size: 14px;
    font-weight: 650;
    text-decoration: none;
    transition: color .25s ease, background .25s ease, transform .25s ease;
}

.animeflix-nav a:hover {
    color: #b83269;
    background: #ffedf4;
    transform: translateY(-2px);
}

.animeflix-nav a:focus-visible,
.animeflix-header .logo:focus-visible {
    outline: 3px solid rgba(217, 79, 131, .4);
    outline-offset: 3px;
}

@media (max-width: 700px) {
    .header-content {
        width: 92%;
        min-height: auto;
        padding: 16px 0 12px;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }

    .animeflix-header .logo {
        font-size: 23px;
    }

    .animeflix-nav {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
        gap: 5px;
    }

    .animeflix-nav a {
        padding: 9px 11px;
        font-size: 12px;
    }
}

@media (max-width: 380px) {
    .animeflix-nav a {
        padding: 8px 9px;
        font-size: 11px;
    }
}
</style>
