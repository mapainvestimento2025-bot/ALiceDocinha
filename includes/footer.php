<footer class="animeflix-footer">
    <div class="footer-content">
        <span class="footer-logo">ALice Docinha</span>
        <p>Catálogo de entretenimento</p>
        <span class="footer-copy">
            © <?= date('Y') ?> ALice Docinha. Todos os direitos reservados.
        </span>
    </div>
</footer>

<style>
.animeflix-footer {
    width: 100%;
    box-sizing: border-box;
    padding: 35px 20px;
    margin-top: 50px;
    text-align: center;
    background: linear-gradient(135deg, #fff0f5, #ffe5ee);
    border-top: 1px solid #f1dce5;
    color: #66535d;
}

.footer-content {
    max-width: 1200px;
    margin: 0 auto;
}

.footer-logo {
    display: block;
    margin-bottom: 8px;
    color: #c83d73;
    font-size: 19px;
    font-weight: 800;
    letter-spacing: 1.5px;
}

.animeflix-footer p {
    margin: 0 0 12px;
    color: #66535d;
    font-size: 14px;
}

.footer-copy {
    color: #927985;
    font-size: 12px;
}

@media (max-width: 600px) {
    .animeflix-footer {
        padding: 28px 15px;
    }
}
</style>

<script src="assets/js/script.js"></script>
</body>
</html>
