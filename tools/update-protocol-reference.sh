#!/usr/bin/env bash
#
# Quick reference: All commands needed to update Minecraft protocol in PocketMine-MP
# Usage: bash tools/update-protocol-reference.sh
#
# Prerequisites (clone once, keep updated):
#   git clone https://github.com/pmmp/BedrockProtocol ../deps/BedrockProtocol
#   git clone https://github.com/pmmp/BedrockData ../deps/BedrockData
#   git clone https://github.com/pmmp/BedrockBlockUpgradeSchema ../deps/BedrockBlockUpgradeSchema
#   git clone https://github.com/pmmp/BedrockItemUpgradeSchema ../deps/BedrockItemUpgradeSchema
#   git clone https://github.com/pmmp/bds-modding-devkit ../deps/bds-modding-devkit
#
# If using a fork (e.g., ItsJosee/BedrockProtocol), clone from there instead.
#

set -e

PMMP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEPS_ROOT="$(cd "$PMMP_ROOT/.." && pwd)/deps"

echo "============================================"
echo "  PocketMine-MP Protocol Update Reference"
echo "============================================"
echo ""
echo "PMMP Root: $PMMP_ROOT"
echo "Deps Root: $DEPS_ROOT"
echo ""

echo "=== PHASE 1: Data Collection (Linux/WSL2 required) ==="
echo ""
echo "# 1a. Generate protocol_info.json from BDS binary:"
echo "cd $DEPS_ROOT/bds-modding-devkit"
echo "python3 protocol_info_dumper.py ./bedrock_server_symbols.debug ./protocol_info.json"
echo "cp protocol_info.json $DEPS_ROOT/BedrockData/"
echo ""
echo "# 1b. Run BDS data extraction mod, copy mapping_files/* to BedrockData:"
echo "cp $DEPS_ROOT/bds-modding-devkit/mapping_files/* $DEPS_ROOT/BedrockData/"
echo ""
echo "# 1c. Capture packet traces:"
echo "# Start BDS: ./bedrock_server_symbols.debug"
echo "# In another terminal:"
echo "sudo python3 tracer.py rw bedrock_server_symbols.debug"
echo "# Join server, do tests, stop server. Note trace filename."
echo ""

echo "=== PHASE 2: Update BedrockProtocol ==="
echo ""
echo "# 2a. Link local deps to PocketMine-MP:"
echo "cd $PMMP_ROOT"
echo "bash install-local-protocol.sh"
echo ""
echo "# 2b. Generate protocol code from BedrockData:"
echo "php $DEPS_ROOT/BedrockProtocol/tools/update-from-bedrock-data.php $DEPS_ROOT/BedrockData"
echo ""
echo "# 2c. Generate ::create() static methods:"
echo "php $DEPS_ROOT/BedrockProtocol/tools/generate-create-static-methods.php"
echo ""
echo "# 2d. Analyze what needs manual work:"
echo "php $PMMP_ROOT/tools/analyze-protocol-changes.php $DEPS_ROOT/BedrockProtocol --verify"
echo ""
echo "# 2e. MANUALLY update packet structures in BedrockProtocol/src/"
echo "#     Reference: https://github.com/Mojang/bedrock-protocol-docs"
echo ""
echo "# 2f. Re-generate ::create() methods after manual changes:"
echo "php $DEPS_ROOT/BedrockProtocol/tools/generate-create-static-methods.php"
echo ""

echo "=== PHASE 3: Generate Supporting Data ==="
echo ""
echo "# 3a. Generate BedrockData from packet trace:"
echo "php $PMMP_ROOT/tools/generate-bedrock-data-from-packets.php <trace-file.txt> $DEPS_ROOT/BedrockData"
echo ""
echo "# 3b. Generate blockstate upgrade schema:"
echo "php $PMMP_ROOT/tools/blockstate-upgrade-schema-utils.php generate <palette-mapping.bin> <output.json>"
echo "# Then copy to: $DEPS_ROOT/BedrockBlockUpgradeSchema/nbt_upgrade_schema/"
echo ""
echo "# 3c. Generate item upgrade schema:"
echo "php $PMMP_ROOT/tools/generate-item-upgrade-schema.php \\"
echo "  $DEPS_ROOT/BedrockData/r16_to_current_item_map.json \\"
echo "  $DEPS_ROOT/BedrockItemUpgradeSchema/id_meta_upgrade_schema \\"
echo "  <output-file.json>"
echo ""

echo "=== PHASE 4: Complete PocketMine-MP Changes ==="
echo ""
echo "# 4a. Run code generation:"
echo "cd $PMMP_ROOT"
echo "composer run-script update-codegen"
echo ""
echo "# 4b. Update WorldDataVersions constants:"
echo "php $PMMP_ROOT/tools/update-world-data-versions.php"
echo ""
echo "# 4c. Run PHPStan and fix errors:"
echo "php vendor/bin/phpstan analyze"
echo ""
echo "# 4d. Run tests:"
echo "php vendor/bin/phpunit tests/phpunit"
echo ""

echo "=== PHASE 5: Verification ==="
echo ""
echo "# 5a. Full verification:"
echo "php $PMMP_ROOT/tools/verify-protocol-update.php"
echo ""
echo "# 5b. Playtest:"
echo "php $PMMP_ROOT/start.php"
echo ""

echo "=== USEFUL COMMANDS ==="
echo ""
echo "# Check current status:"
echo "powershell -File $PMMP_ROOT/tools/update-protocol.ps1 -Phase status"
echo ""
echo "# Run specific phase:"
echo "powershell -File $PMMP_ROOT/tools/update-protocol.ps1 -Phase 2"
echo ""
echo "# Analyze protocol changes (JSON output):"
echo "php $PMMP_ROOT/tools/analyze-protocol-changes.php $DEPS_ROOT/BedrockProtocol --json report.json"
echo ""
