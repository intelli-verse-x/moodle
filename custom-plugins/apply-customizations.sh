#!/bin/bash
echo "[custom-init] Applying persistent customizations..."

# 1. Install/upgrade customcert plugin (mod_customcert v5.0.2)
CUSTOMCERT_SRC=/var/moodledata/custom-plugins/mod/customcert
CUSTOMCERT_DST=/var/www/html/mod/customcert
if [ -d "$CUSTOMCERT_SRC" ]; then
    INSTALLED_VER=""
    if [ -f "$CUSTOMCERT_DST/version.php" ]; then
        INSTALLED_VER=$(grep -oP "release\s*=\s*\"\K[^\"]*" "$CUSTOMCERT_DST/version.php" 2>/dev/null)
    fi
    SOURCE_VER=$(grep -oP "release\s*=\s*\"\K[^\"]*" "$CUSTOMCERT_SRC/version.php" 2>/dev/null)

    if [ "$INSTALLED_VER" != "$SOURCE_VER" ]; then
        echo "[custom-init] Upgrading customcert: ${INSTALLED_VER:-not installed} -> $SOURCE_VER"
        rm -rf "$CUSTOMCERT_DST"
        cp -a "$CUSTOMCERT_SRC" "$CUSTOMCERT_DST"
        chown -R www-data:www-data "$CUSTOMCERT_DST" 2>/dev/null || true
        echo "[custom-init] customcert $SOURCE_VER installed"
    else
        echo "[custom-init] customcert $INSTALLED_VER already up to date"
    fi
fi

# 2. Install/upgrade verify_certs block (block_verify_certs)
VERIFYCERTS_SRC=/var/moodledata/custom-plugins/blocks/verify_certs
VERIFYCERTS_DST=/var/www/html/blocks/verify_certs
if [ -d "$VERIFYCERTS_SRC" ]; then
    if [ ! -f "$VERIFYCERTS_DST/version.php" ]; then
        echo "[custom-init] Installing verify_certs block..."
        cp -a "$VERIFYCERTS_SRC" "$VERIFYCERTS_DST"
        chown -R www-data:www-data "$VERIFYCERTS_DST" 2>/dev/null || true
        echo "[custom-init] verify_certs installed"
    else
        echo "[custom-init] verify_certs already present"
    fi
fi

# 3. Apply email confirmation skip patch
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
