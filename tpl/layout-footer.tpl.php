<?php
/**
 * layout-footer.tpl.php
 *
 * Common credits footer + HTML closing tags for every public HTML
 * view of the SmartAuth OAuth2 portal. Included as the very last line
 * of each tpl/*.tpl.php file:
 *
 *   <?php include __DIR__ . '/layout-footer.tpl.php'; ?>
 *
 * Keep this file self-contained: no $vars expected, no globals.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * License: AGPL-3.0+
 */
?>
    <footer class="portal-credits">
        <div class="portal-credits-inner">
            Made with <span class="portal-heart" aria-label="love">&#10084;</span> by
            <a href="https://www.cap-rel.fr" rel="noopener external">CAP-REL</a>
            -
            SmartAuth, module gratuit pour Dolibarr sous licence
            <a href="https://www.gnu.org/licenses/agpl-3.0.html" rel="noopener external">GNU aGPL</a>
            -
            <a href="https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/" rel="noopener external">Code source</a>
            -
            <a href="/.well-known/openid-configuration" rel="nofollow">Configuration OpenID</a>
        </div>
    </footer>

    <script>
    // Double-submit guard for slow actions (account creation, mail
    // sending...). The form carries data-submit-once; the submit BUTTON is
    // disabled only after the HTML5 validation passed, so an invalid form
    // can still be corrected and submitted.
    document.querySelectorAll('form[data-submit-once]').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = '<span class="loading loading-spinner loading-xs"></span> ' + (btn.dataset.loadingText || btn.textContent);
            }
        });
    });
    </script>
</body>
</html>
