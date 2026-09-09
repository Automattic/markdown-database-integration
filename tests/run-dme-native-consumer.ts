import { execFile } from "node:child_process"
import { mkdir, writeFile } from "node:fs/promises"
import { dirname, join } from "node:path"
import { promisify } from "node:util"

import { buildWordPressPhpunitRecipe } from "/home/chubes/labs/mdi-parity-wave2-consumer/codebox/packages/runtime-core/dist/recipe-builders.js"

async function main(): Promise<void> {
const execFileAsync = promisify(execFile)
const root = process.env.DME_CONSUMER_ROOT ?? "/home/chubes/labs/mdi-parity-wave2-consumer"
const codebox = join(root, "codebox")
const mdi = join(root, "mdi")
const corpus = process.env.DME_CORPUS_ROOT ?? "/home/chubes/labs/mdi-parity-wave2-corpus"
const artifacts = join(root, "artifacts", process.env.DME_RUN_NAME ?? "native-focused")
const selectedTestFile = process.env.DME_TEST_FILE ?? "tests/Integration/EventSourceUpdateMySqlAtomicityTest.php"
const databaseType = process.env.DME_DATABASE_TYPE === "mysql" ? "mysql" : "mdi-native"
const diagnosticPreload = "/wordpress/wp-content/plugins/markdown-database-integration/tests/fixtures/native-query-diagnostic-observer.php"
const phpunitArgs = process.env.DME_PHPUNIT_FILTER ? ["--filter", process.env.DME_PHPUNIT_FILTER] : []

const recipe = buildWordPressPhpunitRecipe({
	wordpressVersion: "7.1",
	phpVersion: "8.3",
	databaseType,
	multisite: true,
	pluginSlug: "data-machine-events",
	pluginSource: join(corpus, "data-machine-events"),
	cwd: "/wordpress/wp-content/plugins/data-machine-events",
	selectedTestFile,
	projectAutoloadFile: "/wordpress/wp-content/plugins/data-machine-events/vendor/autoload.php",
	phpunitXml: "/wordpress/wp-content/plugins/data-machine-events/phpunit.xml",
	preloadFiles: process.env.DME_NATIVE_DIAGNOSTICS === "1" ? [diagnosticPreload] : [],
	phpunitArgs,
	dependencyMounts: ["/wordpress/wp-content/plugins/data-machine"],
	extra_plugins: [{
		source: join(corpus, "data-machine"),
		slug: "data-machine",
		pluginFile: "data-machine/data-machine.php",
		activate: true,
	}],
	mounts: [
		{ source: join(corpus, "phpunit-harness/vendor"), target: "/wp-codebox-vendor", mode: "readonly" },
		{ source: join(corpus, "data-machine/vendor"), target: "/wordpress/wp-content/plugins/data-machine/vendor", mode: "readonly" },
		{ source: join(corpus, "data-machine-events/vendor"), target: "/wordpress/wp-content/plugins/data-machine-events/vendor", mode: "readonly" },
	],
}) as any

if (databaseType === "mdi-native") {
	recipe.inputs.extra_plugins = recipe.inputs.extra_plugins.map((plugin: any) => plugin.slug === "markdown-database-integration"
		? { ...plugin, source: mdi, metadata: { ...plugin.metadata, revision: process.env.MDI_CANDIDATE_SHA } }
		: plugin)
}

const recipePath = join(artifacts, "recipe.json")
await mkdir(dirname(recipePath), { recursive: true })
await writeFile(recipePath, `${JSON.stringify(recipe)}\n`)

try {
	const result = await execFileAsync(process.execPath, ["packages/cli/dist/index.js", "recipe-run", "--recipe", recipePath, "--artifacts", artifacts, "--json"], {
		cwd: codebox,
		timeout: 1_200_000,
		maxBuffer: 8 * 1024 * 1024,
	})
	console.log(result.stdout)
} catch (error) {
	if (error && typeof error === "object" && "stdout" in error && typeof error.stdout === "string") console.log(error.stdout)
	else throw error
	process.exitCode = 1
}
}

main().catch((error: unknown) => {
	console.error(error)
	process.exitCode = 1
})
