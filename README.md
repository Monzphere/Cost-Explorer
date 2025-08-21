## Cost Explorer — Zabbix Module (7.0)


<img width="1735" height="944" alt="image" src="https://github.com/user-attachments/assets/25644d2d-2f82-4233-b9af-6a95d2cdf090" />


A Zabbix frontend module that provides a "Monitoring → Cost Explorer" page to estimate host cost based on CPU cores and memory size, with optional usage-aware breakdowns. Developed and tested with Zabbix 7.0.

## Requirements
- Zabbix Frontend 7.0.x
- PHP web server user must be allowed to write to the module data directory
- Access to Zabbix UI with administrative permissions (to enable modules and configure pricing)

## Installation
1) Copy the module directory to the Zabbix modules folder (adjust the source path if needed):
```bash
sudo mkdir -p /usr/share/zabbix/modules
sudo cp -r CostExplorer /usr/share/zabbix/modules/CostExplorer
```

2) Ensure the module files are readable by the web server user:
```bash
sudo find /usr/share/zabbix/modules/CostExplorer -type f -exec chmod 0644 {} \;
sudo find /usr/share/zabbix/modules/CostExplorer -type d -exec chmod 0755 {} \;
```

## Write permissions for data directory
The module persists pricing configuration at `data/price.json` relative to the module root:

- Module root: `/usr/share/zabbix/modules/CostExplorer`
- Data directory: `/usr/share/zabbix/modules/CostExplorer/data`
- Data file: `/usr/share/zabbix/modules/CostExplorer/data/price.json`

Create the directory and grant ownership to your web server user (on Debian/Ubuntu this is typically `www-data`; on RHEL/CentOS/AlmaLinux with Apache it is usually `apache`). Use the appropriate user/group for your environment:
```bash
# Debian/Ubuntu (Apache or Nginx + PHP-FPM)
sudo mkdir -p /usr/share/zabbix/modules/CostExplorer/data
sudo chown -R www-data:www-data /usr/share/zabbix/modules/CostExplorer/data
sudo chmod 0755 /usr/share/zabbix/modules/CostExplorer/data

# RHEL/CentOS/AlmaLinux (Apache)
# sudo chown -R apache:apache /usr/share/zabbix/modules/CostExplorer/data
# sudo chmod 0755 /usr/share/zabbix/modules/CostExplorer/data
```

Notes:
- 0755 is sufficient if the directory owner is the web server user; group/world do not need write access.
- If SELinux is enforcing (RHEL-based systems), you may need to allow HTTPD writes:
```bash
# Example (adjust if needed)
sudo semanage fcontext -a -t httpd_sys_rw_content_t \
  "/usr/share/zabbix/modules/CostExplorer/data(/.*)?"
sudo restorecon -Rv /usr/share/zabbix/modules/CostExplorer/data
```

Verify permissions:
```bash
ls -ld /usr/share/zabbix/modules/CostExplorer/data
ls -l /usr/share/zabbix/modules/CostExplorer/data
```

## Enabling the module
1) Log in to Zabbix Frontend as an administrator.
2) Go to `Administration → General → Modules`.
3) Locate `Cost Explorer` and click `Enable`.
4) After enabling, a new menu item will appear under `Monitoring → Cost Explorer`.

If you do not see the module listed, verify the directory structure and file permissions, then reload the PHP-FPM/HTTPD service if necessary.

## Configuring pricing (writes to data/price.json)
1) In the Zabbix UI, navigate to `Monitoring → Cost Explorer`.
2) In the pricing section, set the values for:
   - `per_cpu_core`: price per CPU core per hour
   - `per_memory_gb`: price per GB of memory per hour
3) Save. The module will write to `/usr/share/zabbix/modules/CostExplorer/data/price.json`.

If saving fails, re-check the data directory ownership and permissions as described above.

## File layout reference
- `manifest.json`: module manifest and action bindings
- `Module.php`: registers the menu entry under Monitoring
- `views/`: frontend views and layout
- `actions/`: controllers, including pricing persistence
- `assets/`: CSS and JS
- `data/price.json`: pricing configuration (created on first save)

## Upgrade
1) Disable the module in `Administration → General → Modules`.
2) Back up `data/price.json`.
3) Replace the module directory with the new version.
4) Restore `data/price.json` if needed, fix ownership/permissions, and re-enable the module.

## Uninstall
1) Disable the module in `Administration → General → Modules`.
2) Remove the module directory:
```bash
sudo rm -rf /usr/share/zabbix/modules/CostExplorer
```

## Compatibility
- Developed and tested on Zabbix Frontend 7.0.
- Other versions may work but are not officially supported.

## Troubleshooting
- Module not visible under Modules: verify path and permissions under `/usr/share/zabbix/modules/CostExplorer`.
- Cannot save pricing: ensure web server user owns `data/` and directory mode is `0755` (or more permissive as required by your environment).
- Check web server/PHP logs for permission errors.


