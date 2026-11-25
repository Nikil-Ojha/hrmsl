#!/usr/bin/env bash
set -euo pipefail

INSTALL_DIR="/opt/hrms-biometric-agent"
DATA_DIR="/var/lib/hrms-biometric-agent"
SRC_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

if ! id -u hrms-agent >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin hrms-agent
fi

mkdir -p "$INSTALL_DIR" "$DATA_DIR/plugins" "$DATA_DIR/logs"
cp -r "$SRC_DIR/hrms_agent" "$INSTALL_DIR/"
chown -R hrms-agent:hrms-agent "$INSTALL_DIR" "$DATA_DIR"
chmod 750 "$DATA_DIR"

cp "$SRC_DIR/installers/linux/hrms-biometric-agent.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable hrms-biometric-agent

echo "Installed to $INSTALL_DIR."
echo "Next: provision credentials as hrms-agent, then start the service:"
echo "  sudo -u hrms-agent python3 -m hrms_agent --data-dir $DATA_DIR provision --server https://... --agent-id AGT-... --token prov_..."
echo "  sudo systemctl start hrms-biometric-agent"
