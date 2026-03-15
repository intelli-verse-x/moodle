#!/bin/bash
echo "[custom-init] Applying persistent customizations..."

install_plugin() {
    local SRC="$1"
    local DST="$2"
    local LABEL="$3"

    if [ ! -d "$SRC" ]; then
        return
    fi

    INSTALLED_VER=""
    SOURCE_VER=""
    if [ -f "$DST/version.php" ]; then
        INSTALLED_VER=$(grep -oP "version\s*=\s*\K[0-9]+" "$DST/version.php" 2>/dev/null | head -1)
    fi
    SOURCE_VER=$(grep -oP "version\s*=\s*\K[0-9]+" "$SRC/version.php" 2>/dev/null | head -1)

    if [ "$INSTALLED_VER" != "$SOURCE_VER" ]; then
        echo "[custom-init] Installing/upgrading $LABEL: ${INSTALLED_VER:-not installed} -> $SOURCE_VER"
        rm -rf "$DST"
        cp -a "$SRC" "$DST"
        chown -R www-data:www-data "$DST" 2>/dev/null || true
        echo "[custom-init] $LABEL installed"
    else
        echo "[custom-init] $LABEL already up to date ($INSTALLED_VER)"
    fi
}

# Activity modules
install_plugin /var/moodledata/custom-plugins/mod/customcert /var/www/html/mod/customcert "mod_customcert"
install_plugin /var/moodledata/custom-plugins/mod/linkedincert /var/www/html/mod/linkedincert "mod_linkedincert"

# Blocks
install_plugin /var/moodledata/custom-plugins/blocks/verify_certs /var/www/html/blocks/verify_certs "block_verify_certs"

# Enrollment
install_plugin /var/moodledata/custom-plugins/enrol/apply /var/www/html/enrol/apply "enrol_apply"

# Local plugins
install_plugin /var/moodledata/custom-plugins/local/obf /var/www/html/local/obf "local_obf"

# Apply email confirmation skip patch
AUTH_FILE=/var/www/html/auth/email/auth.php
if [ -f "$AUTH_FILE" ]; then
    if ! grep -q "CUSTOM_SKIP_EMAIL_CONFIRM" "$AUTH_FILE"; then
        echo "[custom-init] Applying skip-email-confirmation patch..."
        sed -i "/function user_signup_with_confirmation/,/^    }/ {
            /\\\$auth = get_auth_plugin/i\\
        // CUSTOM_SKIP_EMAIL_CONFIRM - auto-confirm users without email\\
        \\\$user->confirmed = 1;\\
        \\\$user->id = user_create_user(\\\$user, false, false);\\
        \\\\core\\\\event\\\\user_created::create_from_userid(\\\$user->id)->trigger();\\
        if (!\\\$user->id) {\\
            print_error(\"auth_emailnoemail\", \"auth_email\");\\
            return false;\\
        }\\
        \\\\core\\\\event\\\\user_created::create_from_userid(\\\$user->id)->trigger();\\
        return true;\\
        // END CUSTOM_SKIP_EMAIL_CONFIRM
        }" "$AUTH_FILE"
        echo "[custom-init] Email confirmation skip applied"
    else
        echo "[custom-init] Email skip patch already applied"
    fi
fi

echo "[custom-init] All customizations applied."
