**High-Level Overview of OpenEMR**

OpenEMR is the world’s most popular **free and open-source** electronic health records (EHR) and medical practice management system. It is used by doctors, clinics, hospitals, and healthcare organizations worldwide to manage patient records, appointments, billing, prescriptions, lab orders, clinical notes, and insurance claims. It is fully featured, HIPAA-compliant (when properly configured), multi-language (internationalization support), and has a built-in RESTful API + FHIR support for integrations with other systems.

**Key features include**:

- Complete EHR (**Electronic Health Record**) (patient demographics, charts, notes, documents).
- Practice management (scheduling, patient portal, billing/claims).
- Electronic prescribing, lab interfaces, and reporting.
- Multi-user / multi-facility support (sites).
- Modern API and FHIR for third-party apps.
- Community-driven with professional support options.

**Technology stack & architecture** (simple view for beginners):

- **Backend**: PHP 8.x (the core language – ~89% of the code).
- **Frontend**: HTML/CSS/JS with Twig templates; modern JS assets built with npm/Gulp.
- **Database**: MariaDB (stores all patient data, appointments, etc.).
- **Web server**: Apache + PHP-FPM.
- **Architecture style**: Classic PHP web app with MVC-like structure:
  - `public/` = web root (where `index.php` lives – this is what the browser hits).
  - `interface/` = main UI code.
  - `library/` + `src/` = core PHP classes and business logic.
  - `templates/` = Twig view templates.
  - `sql/` + `db/` = database schema and upgrade scripts.
  - `config/` = configuration.
  - `apis/` / `oauth2/` = API layer.
- **Package management**:
  - **Composer** = manages PHP libraries (dependencies are listed in `composer.json`).
  - **npm** = manages JavaScript assets.
- **Deployment**: Officially built around **Docker** (the recommended and easiest way). The official Docker image (`openemr/openemr:latest`) bundles PHP + Apache + the entire OpenEMR code and can auto-install itself when paired with a MySQL container. Persistent data lives in Docker volumes (especially the `sites/` folder for patient data).

The app is mature, actively maintained (current major version 8.x as of 2026), and the repo provides ready-made `docker-compose.yml` files for **development** (with extra debugging tools) and **production**.

**Why Docker is the way to go** (especially for non-PHP/non-DevOps people):  
Everything (PHP, web server, dependencies, Composer install, database) is pre-packaged. You do **not** need to install PHP, Composer, Apache, or MySQL on your computer or server. One command starts the whole stack.

### Part 1: Running OpenEMR Locally (Easiest Method – Docker)

This is the **recommended path** for beginners. It takes 10–15 minutes once tools are installed.

#### Step 0: Install Required Tools (Git + Docker) – One-Time Setup

**Windows**:

1. Download and install **Git** → [https://git-scm.com/downloads](https://git-scm.com/downloads) (choose “Git Bash” during install).
2. Download and install **Docker Desktop** → [https://www.docker.com/products/docker-desktop/](https://www.docker.com/products/docker-desktop/). Run it and keep it open (it runs in the background).
3. Restart your computer.

**macOS**:

1. Install **Homebrew** (if you don’t have it): open Terminal and run
  `/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"`
2. Install Git + Docker:
  ```bash
   brew install git
   brew install --cask docker
  ```
3. Open Docker Desktop from Applications and let it start.

**Linux (Ubuntu 22.04/24.04 – recommended for beginners)**:

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install git -y
# Install Docker
sudo apt install ca-certificates curl -y
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin -y
sudo usermod -aG docker $USER
# Log out and log back in (or reboot)
```

**Verify everything works** (in Terminal / Git Bash / PowerShell):

```bash
git --version
docker --version
docker compose version
```

#### Step 1: Clone the Repository and Start OpenEMR

Open Terminal / Git Bash and run:

```bash
git clone https://github.com/openemr/openemr.git
cd openemr/docker/development-easy
docker compose up -d
```

- This pulls the latest OpenEMR + MariaDB images and starts them.
- First run takes 2–5 minutes while it auto-installs the database.
- You will see logs about Apache and cron starting.

#### Step 2: Access OpenEMR

- **Web app**: [http://localhost:8300](http://localhost:8300) (or [https://localhost:9300](https://localhost:9300))
  - Default login: **username** = `admin`  
  **password** = `pass`
- **phpMyAdmin** (to browse the database): [http://localhost:8310](http://localhost:8310)  
  - Login: `user` / `openemr`
- **Direct MySQL port** (for tools like MySQL Workbench): port 8320, user `openemr`, password `openemr`.

**Done!** You have a fully working OpenEMR instance.

**Useful commands** (while in the `development-easy` folder):

- Stop: `docker compose down`
- Stop + delete all data (fresh start): `docker compose down -v`
- Update images: `docker compose pull && docker compose up -d`
- See logs: `docker compose logs -f openemr`

**Code changes sync live** because the Docker volume mounts your local files. Edit files in your editor (VS Code recommended) → refresh browser.

**If you want to build JS/CSS assets** (only needed for some theme changes):

```bash
docker compose exec openemr /root/devtools build-themes
```

### Part 2: (Optional) Manual Install Without Docker (Not Recommended for Beginners)

Only do this if you really want to run natively. It requires:

- PHP 8.2+ (with many extensions: mysqli, gd, curl, etc.)
- MariaDB/MySQL 10.11+
- Composer + npm + web server (Apache/Nginx)

**Quick Composer install** (if you ever need it):

- **Windows**: Download Composer-Setup.exe from [https://getcomposer.org/download/](https://getcomposer.org/download/)
- **macOS**: `brew install composer`
- **Linux**:  
  ```bash
  sudo apt install php php-cli php-mbstring unzip -y
  curl -sS https://getcomposer.org/installer -o composer-setup.php
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  ```

Then in the repo root: `composer install --no-dev`, `npm install && npm run build`, run `setup.php` in browser, etc. This is significantly more complex and error-prone – stick with Docker.

### Part 3: Deploying to Production (Cheap & Easy)

**My recommendation**: Use a **DigitalOcean Droplet** (or any cheap VPS like Vultr, Linode, Hetzner).  
Why not Railway?  

- Railway is excellent for simple apps, but OpenEMR is a multi-container app with persistent volumes and a database. You would have to recreate the entire `docker-compose.yml` as separate Railway services, manage volumes manually, and handle Let’s Encrypt yourself.  
- A VPS gives you **exact** Docker Compose + full control, automatic upgrades, easy backups, and costs ~$6/month (cheaper long-term than burning Railway credits on a database-heavy EHR).  
- DigitalOcean has one-click Ubuntu + excellent docs and $200 free credit for new users.

**Cost**: Basic Droplet (1 vCPU / 1 GB RAM / 25 GB SSD) = $6/month. Plenty for OpenEMR with a few users. Scale up later if needed.

#### Step-by-Step Deployment to DigitalOcean Droplet

1. **Create the Droplet**:

- Sign up at [https://cloud.digitalocean.com](https://cloud.digitalocean.com) (use referral for free credit).
- Create → Droplet → Ubuntu 24.04 LTS → Basic → $6 plan (Regular, 1 GB).
- Choose a region close to your users.
- Add your SSH key (or use password – SSH key is more secure).
- Create Droplet. Note the IP address.

1. **SSH into the Droplet** (from your computer):

- **Mac/Linux**: `ssh root@YOUR_DROPLET_IP`
- **Windows**: Use Git Bash or PuTTY.

1. **Install Docker + Git on the server** (copy-paste these exact commands):

```bash
apt update && apt upgrade -y
apt install git -y

# Install Docker (official way)
apt install ca-certificates curl -y
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null
apt update
apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin -y

# Allow your user to run Docker without sudo
usermod -aG docker $USER   # log out and back in after this
```

1. **Clone & start production OpenEMR**:

```bash
git clone https://github.com/openemr/openemr.git
cd openemr/docker/production
```

1. **Create a strong .env file** (or edit the docker-compose.yml directly):

```bash
nano .env
```

Paste (change the passwords!):

```
MYSQL_ROOT_PASS=SuperStrongRootPassword123!
OE_USER=admin
OE_PASS=SuperStrongAdminPassword123!
# Optional: for automatic Let's Encrypt SSL
DOMAIN=yourdomain.com
EMAIL=you@email.com
```

1. **Start it**:

```bash
docker compose up -d
```

1. **Open firewall ports** (on the Droplet):

```bash
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
```

1. **Point your domain** (if you have one):

- In your domain registrar, create an **A record** pointing to the Droplet IP.
- Wait 5–10 minutes for DNS propagation.
- Visit `https://yourdomain.com` → it should have a valid Let’s Encrypt certificate (thanks to the Docker image).

**Access**: [https://yourdomain.com](https://yourdomain.com) (or [http://YOUR_IP](http://YOUR_IP) if no domain).  
Login with the OE_USER / OE_PASS you set.

**Update OpenEMR later**:

```bash
cd openemr/docker/production
docker compose pull
docker compose up -d
```

(The image auto-upgrades the database on restart.)

**Backups** (very important for EHR):

- Database: `docker compose exec mysql mysqldump -u root -p$MYSQL_ROOT_PASS openemr > backup.sql`
- Sites folder: back up the Docker volume (`docker volume ls` and use `docker volume inspect`).

**Security tips** (do these!):

- Change default passwords immediately.
- Enable UFW firewall.
- Use strong SSH keys (disable password login).
- Consider adding fail2ban.
- Regularly run `docker compose pull && docker compose up -d`.

### Final Notes & Resources

- **You now have a production-grade OpenEMR instance** that you can use locally for testing and in the cloud for real patients.
- The official Docker image handles Composer, npm builds, database migrations, and upgrades automatically.
- Video tutorials (highly recommended): Search YouTube for “OpenEMR Easy Docker Development Environment” – the series by the OpenEMR team is excellent.
- Official docs: [https://github.com/openemr/openemr/blob/master/CONTRIBUTING.md](https://github.com/openemr/openemr/blob/master/CONTRIBUTING.md) (development) and the `DOCKER_README.md` in the repo.
- Support: [https://community.open-emr.org/](https://community.open-emr.org/) (very friendly).

You do **not** need to be a PHP or DevOps expert anymore – Docker does the heavy lifting. Follow the steps exactly and you’ll have a working system in under an hour. If you get stuck on any single command, paste the error here and I’ll help you fix it. Welcome to the OpenEMR community!