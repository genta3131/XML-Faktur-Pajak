// Self-check: kutip CSV + anti formula injection.
const assert = require('node:assert');
const { csvCell } = require('../app/assets/escape.js');

assert.strictEqual(csvCell(null), '""');
assert.strictEqual(csvCell(12), '"12"');
assert.strictEqual(csvCell('a"b'), '"a""b"');
assert.strictEqual(csvCell('=1+1'), '"\'=1+1"');
assert.strictEqual(csvCell('+SUM(A1)'), '"\'+SUM(A1)"');
assert.strictEqual(csvCell('-2'), '"\'-2"');
assert.strictEqual(csvCell('@x'), '"\'@x"');
assert.strictEqual(csvCell('RefDesc'), '"RefDesc"');
