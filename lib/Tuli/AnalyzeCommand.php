<?php

namespace Tuli;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use PhpParser\Parser;
use PhpParser\Lexer;
use PHPCfg\Parser as CFGParser;
use PHPCfg\Block;
use PHPCfg\Visitor;
use PHPCfg\Traverser;
use PHPCfg\Operand;
use PHPCfg\Op;

class AnalyzeCommand extends Command {

	protected $rules = [];

	protected function configure() {
		$this->setName('analyze')
			->setDescription('Analyze the provided files')
			->addOption('exclude', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Extensions To Exclude?", ["md", "xml", "yml", "json"])
			->addArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The files to analyze');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->loadRules($input);
		$parser = new CFGParser(new Parser(new Lexer));
		$graphs = $this->getGraphsFromFiles($input->getArgument('files'), $input->getOption("exclude"), $parser);
		$components = $this->preProcess($graphs, $output);
		$components = $this->computeTypeMatrix($components);
		$components['typeResolver'] = new TypeResolver($components);

		echo "Determining Variable Types\n";
		$typeReconstructor = new TypeReconstructor;
		$typeReconstructor->resolve($components);

		echo "Detecting Type Conversion Issues\n";
		$errors = [];
		foreach ($this->rules as $rule) {
			echo "Executing rule: " . $rule->getName() . "\n";
			$errors = array_merge($errors, $rule->execute($components));
		}
		if ($errors) {
			echo "\nErrors found:\n";
			foreach ($errors as $error) {
				$this->emitError($error[0], $error[1]);
			}
		}
	}

	protected function loadRules(InputInterface $input) {
		$this->rules[] = new Rule\ArgumentType;
		$this->rules[] = new Rule\ReturnType;
	}

	protected function getGraphsFromFiles(array $files, array $exclude, CFGParser $parser) {
		$excludeParts = [];
		foreach ($exclude as $part) {
			$excludeParts[] = preg_quote($part);
		}
		$part = implode('|', $excludeParts);
		$excludeRegex = "(((\\.($part)($|/))|((^|/)($part)($|/))))";
		$graphs = [];
		foreach ($files as $file) {
			if (is_file($file)) {
				$files = [$file];
			} elseif (is_dir($file)) {
				$it = new \CallbackFilterIterator(
					new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator($file)
					), 
					function($file) use ($excludeRegex) {
						if (preg_match($excludeRegex, $file->getPathName())) {
							return false;
						}
						return $file->isFile();
					}
				);
				$files = [];
				foreach ($it as $file) {
					$files[] = $file->getPathName(); // since __toString would be too difficult...
				}
			} else {
				throw new \RuntimeException("Error: $file is not a file or directory");
			}
			foreach ($files as $file) {
				echo "Analyzing $file\n";
				$graphs[$file] = $parser->parse(file_get_contents($file), $file);
			}
		}
		return $graphs;
	}

	protected function preProcess(array $blocks, OutputInterface $output) {
		$traverser = new Traverser;
		$declarations = new Visitor\DeclarationFinder;
		$calls = new Visitor\CallFinder;
		$variables = new Visitor\VariableFinder;
		$dagComputer = new Visitor\VariableDagComputer;
		$traverser->addVisitor(new Visitor\Simplifier);
		$traverser->addVisitor($dagComputer);
		$traverser->addVisitor($declarations);
		$traverser->addVisitor($calls);
		$traverser->addVisitor($variables);
		foreach ($blocks as $block) {
			$traverser->traverse($block);
		}
		$vars = $variables->getVariables();
		
		return [
			"cfg" => $blocks,
			"traits" => $declarations->getTraits(),
			"classes" => $declarations->getClasses(),
			"methods" => $declarations->getMethods(),
			"functions" => $declarations->getFunctions(),
			"functionLookup" => $this->buildFunctionLookup($declarations->getFunctions()),
			"interfaces" => $declarations->getInterfaces(),
			"variables" => $variables->getVariables(),
			"callResolver" => $calls,
			"methodCalls" => $this->findMethodCalls($blocks),
		];
	}

	protected function findMethodCalls(array $blocks) {
		$methodCalls = [];
        foreach ($blocks as $block) {
            $methodCalls = $this->findTypedBlock("Expr_MethodCall", $block, $methodCalls);
        }
        return $methodCalls;
	}

	protected function computeTypeMatrix($components) {
		// TODO: This is dirty, and needs cleaning
		// A extends B
		$map = []; // a => [a, b], b => [b]
		$interfaceMap = [];
		$classMap = [];
		$toProcess = [];
		foreach ($components['interfaces'] as $interface) {
			$name = strtolower($interface->name->value);
			$map[$name] = [];
			$interfaceMap[$name] = [];
			if ($interface->extends) {
				foreach ($interface->extends as $extends) {
					$interfaceMap[$name][] = strtolower($extends->value);
				}
			}
		}
		foreach ($components['classes'] as $class) {
			$name = strtolower($class->name->value);
			$map[$name] = [$name => $class];
			$classMap[$name] = [$name];
			foreach ($class->implements as $interface) {
				$iname = strtolower($interface->value);
				$classMap[$name][] = $iname;
				$map[$iname][$name] = $class;
				if (isset($interfaceMap[$iname])) {
					foreach ($interfaceMap[$iname] as $sub) {
						$classMap[$name][] = $sub;
						$map[$sub][$name] = $class;
					}
				}
			}
			if ($class->extends) {
				$toProcess[] = [$name, strtolower($class->extends->value), $class];
			}
		}
		foreach ($toProcess as $ext) {
			$name = $ext[0];
			$extends = $ext[1];
			$class = $ext[2];
			if (isset($classMap[$extends])) {
				foreach ($classMap[$extends] as $mapped) {
					$map[$mapped][$name] = $class;
				}
			} else {
				echo "Could not find parent $extends\n";
			}
		}
		$components['resolves'] = $map;
		return $components; 
	}

	protected function buildFunctionLookup(array $functions) {
		$lookup = [];
		foreach ($functions as $function) {
			assert($function->name instanceof Operand\Literal);
			$name = strtolower($function->name->value);
			if (!isset($lookup[$name])) {
				$lookup[$name] = [];
			}
			$lookup[$name][] = $function;
		}
		return $lookup;
	}

	protected function findTypedBlock($type, Block $block, $result = []) {
		$toProcess = new \SplObjectStorage;
		$processed = new \SplObjectStorage;
		$toProcess->attach($block);
		foreach ($toProcess as $block) {
			$toProcess->detach($block);
			$processed->attach($block);
			foreach ($block->children as $op) {
				if ($op->getType() === $type) {
					$result[] = $op;
				}
				foreach ($op->getSubBlocks() as $name) {
					$sub = $op->$name;
					if (is_null($sub)) {
						continue;
					}
					if (!is_array($sub)) {
						$sub = [$sub];
					}
					foreach ($sub as $subb) {
						if (!$processed->contains($subb)) {
							$toProcess->attach($subb);
						}
					}
				}
			}
		}
		return $result;
	}

	protected function emitError($msg, Op $op) {
		echo $msg;
		echo " ";
		echo $op->getFile() . ":" . $op->getLine();
		echo "\n";
	}

}