# Local Magento Test Environment (Docker)

This guide sets up a local Magento instance in Docker and installs the `Paypercut_Payment` module for testing.  
No PHP or Composer is required on your host machine.

## Prerequisites

- Docker (Docker Desktop / Docker Engine)
- `curl`
- Linux / WSL2 recommended

## 1) Create a fresh Magento test workspace

Pick a clean folder (example uses `~/magento-test`):

```bash
mkdir -p ~/magento-test
cd ~/magento-test
```

Generate the docker-magento project scaffold:

```bash
curl -s https://raw.githubusercontent.com/markshust/docker-magento/master/lib/template | bash
```

Create the `src/` directory (some script flows require it to exist early):

```bash
mkdir -p src
```

## 2) Configure Magento repo credentials (Access Keys)

Magento is downloaded from `repo.magento.com` using **Access Keys** (public/private), not your Marketplace login password.
If you need to create or get these keys see https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/prerequisites/authentication-keys?utm_source=chatgpt.com


Create `src/auth.json`:

```bash
cat > src/auth.json <<'JSON'
{
  "http-basic": {
    "repo.magento.com": {
      "username": "YOUR_PUBLIC_KEY",
      "password": "YOUR_PRIVATE_KEY"
    }
  }
}
JSON
```

## 3) Download Magento (inside Docker)

Download Magento Open Source (Community Edition). Example version:

```bash
bin/download community 2.4.8-p3
```

If this fails with authentication, your Access Keys are wrong or not active.

## 4) Start containers

```bash
bin/start
```

Verify the PHP container is up:

```bash
docker compose ps
```

## 5) Install Magento

```bash
bin/setup magento.test
```

If you want to use `localhost` instead of `magento.test`, you can typically access via the ports printed by `bin/setup`.  
If `magento.test` is used, add it to your hosts file:

- Linux/WSL: `/etc/hosts`
- Windows: `C:\Windows\System32\drivers\etc\hosts`

Add:

```
127.0.0.1 magento.test
```

## 6) Verify Magento CLI works

```bash
bin/cli /var/www/html/bin/magento --version
```

## 7) Install this module into Magento

Magento modules under `app/code` must follow:

`app/code/<Vendor>/<Module>`

For `Paypercut_Payment` this is:

`app/code/Paypercut/Payment`

### Option A: Copy module source into the container (fastest)

From your module repo root (or wherever your module folder lives):

```bash
# inside the docker-magento project folder:
bin/cli mkdir -p app/code/Paypercut
docker cp /path/to/Paypercut/Payment "$(docker compose ps -q php-fpm)":/var/www/html/app/code/Paypercut/Payment
```

Sanity check:

```bash
bin/cli ls -la app/code/Paypercut/Payment
```

### Enable + upgrade

```bash
bin/cli bin/magento module:enable Paypercut_Payment
bin/cli bin/magento setup:upgrade -vvv
bin/cli bin/magento cache:flush
```

## 8) Installing from a ZIP (simulate Marketplace)

Copy the ZIP into the container and unzip into the module path:

```bash
docker cp /path/to/paypercut-payment-1.1.0.zip "$(docker compose ps -q php-fpm)":/tmp/mod.zip
bin/cli rm -rf app/code/Paypercut/Payment
bin/cli mkdir -p app/code/Paypercut/Payment
bin/cli unzip -o /tmp/mod.zip -d app/code/Paypercut/Payment

bin/cli bin/magento module:enable Paypercut_Payment
bin/cli bin/magento setup:upgrade -vvv
```

## 9) Logs when something fails

```bash
bin/cli tail -n 200 var/log/exception.log || true
bin/cli tail -n 200 var/log/system.log || true
```

## 10) Reset everything

This removes containers/volumes for this test environment:

```bash
docker compose down -v --remove-orphans
```

If you also want to remove the generated project folder, just delete it.
