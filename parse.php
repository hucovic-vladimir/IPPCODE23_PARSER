<?php
// Tato třída se stará o sběr statistik z kódu IPPcode23
class StatisticsCollector{
    public string $errorMessage;
    public int $errorCode;
    public int $commentCount;
    public array $instructions;

    // ukládá definovaná návěští a jejich pořadí
    private ?array $labels;
    // ukládá instrukce skoku a jejich pořadí
    private ?array $jumps;

	// Konstruktor třídy StatisticsCollector přijímá jako parametr objekt třídy Parser
	// a získává z něj pole instrukcí a počet komentářů
    public function __construct(Parser $parser){
        $this->instructions = $parser->instructions;
        $this->commentCount = $parser->commentCount;
        $this->labels = null;
        $this->jumps = null;
    }

	// Získá z pole instrukcí definovaná návěští a instrukce skoku a jejich pořadí
	// a uloží je do proměnných $labels a $jumps
	// Díky tomu se nemusí při sběru statistik o skocích pokaždé získávat návěští a skoky
    private function GetLabelsAndJumps() : void{
        $instCount = count($this->instructions);
        $this->labels = array();
        $this->jumps = array();

        for($i = 0; $i < $instCount; $i++){
            if($this->instructions[$i]->opcode === "LABEL"){
                $this->labels[$i] = $this->instructions[$i]->args[0]->value;
            }

            if($this->instructions[$i]->opcode === "JUMP" || $this->instructions[$i]->opcode === "JUMPIFEQ" ||
                $this->instructions[$i]->opcode === "JUMPIFNEQ" || $this->instructions[$i]->opcode === "CALL"){
                $this->jumps[$i] = $this->instructions[$i]->args[0]->value;
            }
        }
    }

    // vrací počet komentářů v kódu
    public function GetCommentCount() : int{
        return $this->commentCount;
    }

	// vrací počet instrukcí v kódu
    public function GetInstructionCount() : int{
        return count($this->instructions);
    }

	// vrací počet unikátních návěští v kódu
    public function GetLabelCount() : int{
        $labels = array();
        foreach($this->instructions as $inst){
            if($inst->opcode === "LABEL"){
                $labels[$inst->args[0]->value] = true;
            }
        }
        return count($labels);
    }

	// vrací agregovaný počet instrukcí specifikovaných parametry $opcodes
    public function GetOpcodesCount(string ...$opcodes) : int{
        $count = 0;
        foreach($this->instructions as $inst){
            foreach($opcodes as $opcode){
                if($inst->opcode === strtoupper($opcode)) $count++;
            }
        }
        return $count;
    }

	// vrací pole nejčastěji používaných instrukcí
    public function GetMostFrequentOpcodes() : array{
        $opcodes = array();
        foreach($this->instructions as $inst){
            if(isset($opcodes[$inst->opcode])) $opcodes[$inst->opcode]++;
            else $opcodes[$inst->opcode] = 1;
        }
        $opcodeCounts = array_values($opcodes);
        if(!$opcodeCounts) return array();
		// Získá maximální hodnotu z pole $opcodes a najde všechny klíče, které mají tuto hodnotu
		// a přidá je do pole
        $max = max(array_values($opcodes));
		$maxOpcodes = array_keys($opcodes, $max);
		sort($maxOpcodes);
        return $maxOpcodes;
	}

	// vrací počet skoků na neexistující návěští
    public function GetBadJumpsCount() : int{
        if($this->labels === null || $this->jumps === null) $this->GetLabelsAndJumps();
        $badJumps = 0;
        foreach($this->jumps as $jumpOrder => $jumpLabel){
            $labelOrder = array_search($jumpLabel, $this->labels);
            if($labelOrder === false) $badJumps++;
        }
        return $badJumps;
    }

	// vrací počet dopředných skoků
    public function GetForwardJumpsCount() : int{
        if(!$this->labels || !$this->jumps) $this->GetLabelsAndJumps();
        $fwJumps = 0;
        foreach($this->jumps as $jumpOrder => $jumpLabel){
            $jumpDestinations = array_keys($this->labels, $jumpLabel);
            foreach($jumpDestinations as $jumpDest){
                if($jumpOrder < $jumpDest){
                    $fwJumps++;
                    break;
                }
            }
        }
        return $fwJumps;
    }

	// vrací počet zpětných skoků
    public function GetBackwardJumpsCount() : int{
        if(!$this->labels || !$this->jumps) $this->GetLabelsAndJumps();
        $backJumps = 0;
        foreach($this->jumps as $jumpOrder => $jumpLabel){
            $jumpDestinations = array_keys($this->labels, $jumpLabel);
            foreach($jumpDestinations as $jumpDest){
                if($jumpOrder > $jumpDest){
                    $backJumps++;
                    break;
                }
            }
        }
        return $backJumps;
    }

	// vrací řětězec s požadovanými statistikami
    public function GetStatistics(array $requestedStatistics) : ?string{
        $collectedStatisticsString = "";
        foreach($requestedStatistics as $request){
            switch($request){
                case "loc": $collectedStatisticsString .= $this->GetInstructionCount() . "\n"; break;
                case "comments": $collectedStatisticsString .= $this->GetCommentCount() . "\n"; break;
                case "labels": $collectedStatisticsString .= $this->GetLabelCount() . "\n"; break;
                case "jumps": $collectedStatisticsString .= 
                                $this->GetOpcodesCount("jump", "jumpifeq", "jumpifneq", "call", "return") . "\n"; break;
                case "fwjumps": $collectedStatisticsString .= $this->GetForwardJumpsCount() . "\n"; break;
                case "backjumps": $collectedStatisticsString .= $this->GetBackwardJumpsCount() . "\n"; break;
                case "badjumps": $collectedStatisticsString .= $this->GetBadJumpsCount(). "\n"; break;
                case "eol": $collectedStatisticsString .= "\n"; break;
                case "frequent":
                    $mostFrequent = $this->GetMostFrequentOpcodes();
                    for($i = 0; $i < count($mostFrequent); $i++){
                        $collectedStatisticsString .=  $mostFrequent[$i];
                        if($i + 1 != count($mostFrequent)) $collectedStatisticsString .= ",";
                    }
                    $collectedStatisticsString .= "\n"; break;
                default:
                    if(preg_match("/^print=/", $request)){
                        $split = explode("=", $request, 2);
                        $collectedStatisticsString .= $split[1];
                    }
                    else{
                        $this->errorCode = 10;
						$this->errorMessage = "Neznámá statistika: $request\n" . "Nápověda: --help\n";
                        return null;
                    }
            } // switch($request)
        } // foreach($requestedStatistics as $request)
        return $collectedStatisticsString;
    } // GetStatistics()
} // class StatisticsCollector

// Objekt Parser se stará o lexikání a syntaktickou analýzu zdrojového kódu
class Parser{
    // konstanta, která obsahuje správnou syntaxi pro jednotlivé instrukce jazyka IPPcode23
    private const SYNTAX = array(
        "MOVE" => array("var", "symb"),
        "CREATEFRAME" => array(),
        "PUSHFRAME" => array(),
        "POPFRAME" => array(),
        "DEFVAR" => array("var"),
        "CALL" => array("label"),
        "RETURN" => array(),
        "PUSHS" => array("symb"),
        "POPS" => array("var"),
        "ADD" => array("var", "symb", "symb"),
        "SUB" => array("var", "symb", "symb"),
        "MUL" => array("var", "symb", "symb"),
        "IDIV" => array("var", "symb", "symb"),
        "LT" => array("var", "symb", "symb"),
        "GT" => array("var", "symb", "symb"),
        "EQ" => array("var", "symb", "symb"),
        "AND" => array("var", "symb", "symb"),
        "OR" => array("var", "symb", "symb"),
        "NOT" => array("var", "symb"),
        "INT2CHAR" => array("var", "symb"),
        "STRI2INT" => array("var", "symb", "symb"),
        "WRITE" => array("symb"),
        "READ" => array("var", "type"),
        "CONCAT" => array("var", "symb", "symb"),
        "STRLEN" => array("var", "symb"),
        "GETCHAR" => array("var", "symb", "symb"),
        "SETCHAR" => array("var", "symb", "symb"),
        "TYPE" => array("var", "symb"),
        "LABEL" => array("label"),
        "JUMP" => array("label"),
        "JUMPIFEQ" => array("label", "symb", "symb"),
        "JUMPIFNEQ" => array("label", "symb", "symb"),
        "EXIT" => array("symb"),
        "DPRINT" => array("symb"),
        "BREAK" => array(),
    ); 

	// uchovává chybový kód
	public int $errorCode = 0;

	// uchovává chybovou hlášku
	public string $errorMessage = "";

	// uchovává číslo řádku, který se momentálně zpracovává
	public int $currentLineNumber = 0;

	// pole objektů Instruction, které jsou zde uloženy po úspěšném zpracování zdrojového kódu
    public array $instructions = array();

	// počet komentářů v zdrojovém kódu	
    public int $commentCount = 0;

    public function __construct(){}


	// Zpracuje soubor s danou cestou a uloží objekty Instruction do pole $instructions
	// Vrací true, pokud se zpracování povedlo, jinak false a nastaví chybový kód a hlášku
    public function ParseFile(string $filePath) : bool{
        $head = false;
        $file = fopen($filePath, 'r');
        if(!$file){
            $this->errorMessage = "Nepodařilo se otevřít soubor $filePath\n";
            $this->errorCode = 11;
            return false;
        }
		// přeskočí prázdné nebo komentované řádky před hlavičkou
        while(($line = fgets($file))){
            $this->currentLineNumber++;
            $trimmed = trim($line);
            if($trimmed == '') continue; // přeskoč prázdné řádky 
            // přeskoč zakomentované řádky
            else if(preg_match("/^#.*/", $trimmed)){
                $this->commentCount++;
                continue; 
            }
            else{
                if(strpos($trimmed, "#")) $this->commentCount++;
                // kontrola, jestli je první nekomentovaný neprázdný řádek hlavička
                if(preg_match("/^(\.ippcode23)(\s)*((#+.*)*)$/i",$trimmed)) $head = true;
                break;
            }
        }

        if(!$head){
			$this->errorMessage = "Neplatná nebo chybějící hlavička!\n";
            $this->errorCode = 21;
            return false;
        }

        // pokud je hlavička v pořádku, pokračuj v zpracování
        while(($line = fgets($file))){
            $this->currentLineNumber++;
            // najde pozici komentáře
            $commentPos = strpos($line, "#");
            // pokud je na řádku komentář, odřízne ho 
            if($commentPos !== false){
                $this->commentCount++;
                $line = substr($line, 0, -(strlen($line)-$commentPos));
            }
            // odřízne bílé znaky na začátku a na konci řádku 
            $line = trim($line);
            if($line == '') continue;

			// zpracuje instrukci a uloží ji do pole $instructions, nebo vrátí false v případě chyby
            $inst = $this->ParseInstruction($line);
            if(!$inst) return false;
            else array_push($this->instructions, $inst);
        }
        return true;
    } // ParseFile()

	// zpracuje instrukci a vrátí objekt Instruction, nebo null v případě chyby
    private function ParseInstruction(string $instruction) : ?Instruction{
		static $instOrder = 1;
		// rozdělí řetězec s instrukcí do pole
        $lineSplit = preg_split("/(\s)+/", $instruction);

        // lexikální kontrola
        if(!preg_match("/[a-z]/i", $lineSplit[0])) {
            print("Neplatná instrukce na řádku $this->currentLineNumber\n"); exit(23);
        }

		$opcode = strtoupper($lineSplit[0]);
		// kontrola, jestli je instrukce platná
        $syntax = self::SYNTAX[strtoupper($lineSplit[0])] ?? false;
        if($syntax === false){
            $this->errorCode = 22;
            $this->errorMessage = "Neplatná instrukce na řádku $this->currentLineNumber\n";
            return null;
        }

       // získání argumentů, kontrola počtu argumentů 
        $args = array_slice($lineSplit, 1);
        $argCount = count($args);
        $argObjectsArray = array();

        if($argCount > count($syntax)) {
			$this->errorMessage = "Příliš mnoho argumentů pro instrukci na řádku $this->currentLineNumber\n";
            $this->errorCode = 23;
            return null;
        }

        if($argCount < count($syntax)){
			$this->errorMessage = "Příliš málo argumentů pro instrukci na řádku $this->currentLineNumber\n";
            $this->errorCode = 23;
            return null;
        }
        
		else{
			// Pokusí se vytvořit objekty Argument pro každý argument instrukce
            for($i = 0; $i < $argCount; $i++){
                $argObject = null;
                $argType = $syntax[$i];
                $argObject = ArgumentFactory::CreateArgument($argType, $args[$i]);
                if($argObject === null){
                    $this->errorCode = 23;
						$this->errorMessage = "Argument na pozici " . $i+1 . " je špatného typu na řádku $this->currentLineNumber\n";
                    return null;
                }
                else array_push($argObjectsArray, $argObject);
            }
		}
        return new Instruction($opcode, $argObjectsArray, $instOrder++);
    } // ParseInstruction()

	// vrací XML reprezentaci instrukcí
    public function GenerateOutput() : string{
        $output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $output .= "<program language=\"IPPcode23\">\n";
        
        foreach($this->instructions as $instruction){
            $output .= $instruction->toXML();
        }

        $output .= "</program>\n";

        return $output;
    }
} // class Parser

// Rodičovská třída pro prvky, které jsou konvertovány do XML (argumenty a instrukce)
abstract class CodeElement{
	// Vrací XML reprezentaci objektu
    public abstract function toXML() : string;
}

// Tovární třída, která vytváří objekty, které dědí z třídy Argument
class ArgumentFactory{
	// Zkusí vytvořit objekt Argument zadaného typu a hodnoty
	// Vrátí null pokud se to nepodaří
    public static function CreateArgument(string $type, string $value) : ?Argument{
        switch($type){
            case "symb":
                return Symbol::Create($value);
            case "var":
                return Variable::Create($value);
            case "const":
                return Constant::Create($value);
            case "label":
                return Label::Create($value);
            case "type":
                return Type::Create($value);
            default: 
                return null;               
        }
    }   
}

// Třída reprezentující instrukce v IPPcode23
class Instruction extends CodeElement{
    public string $opcode;
    public array $args; // pole objektů třídy Argument
    public int $order;

    public function __construct(string $opcode, array $args, int $order){
        $this->opcode = $opcode;
        $this->args = $args;
        $this->order = $order;
    }

	// vrací XML reprezentaci instrukce
	public function toXML() : string{
		// iteruje přes argumenty a vytváří jejich XML reprezentaci
        $argXML = "\n";
        $argCount = count($this->args);
        if($argCount === 0){
            return "\t<instruction opcode=\"$this->opcode\" order=\"$this->order\"/>\n";
        }
        for($i = 1; $i <= $argCount; $i++){
            $argXML .= $this->args[$i-1]->toXML($i);
            $argXML .= "\n";
		}
        return "\t<instruction opcode=\"$this->opcode\" order=\"$this->order\">$argXML\t</instruction>\n";
    }
}

// Abstraktní třída ze které dědí všechny typy argumentů
abstract class Argument extends CodeElement{
    public $value;

    // Objekty třídy Argument se mohou vytvořit pouze pomocí této metody
    public abstract static function Create(string $value) : ?Argument ;

	// Ověřuje, zda se jedná o platný argument daného typu
    protected abstract static function IsValid(string $value) : bool;

    protected function __construct(string $value){
        $this->value = $value;
    }
}

// Abstrktní třída ze které dědí všechny typy symbolů 
abstract class Symbol extends Argument{

	// Pokusí se vytvořit objekt Symbol zadané hodnoty
    public static function Create(string $symb) : ?Symbol{
        $symbObject = Variable::Create($symb);
        if($symbObject === null) $symbObject = Constant::Create($symb);
        return $symbObject;
    }

	// Ověřuje, zda se jedná o platný symbol
    protected static function IsValid(string $symb) : bool{
        return Variable::IsValid($symb) || Constant::IsValid($symb);
    }
}

// Třída reprezentující proměnné v IPPcode23
class Variable extends Symbol{
    public string $frame;

    private function __construct(string $var){
        $split = explode("@", $var, 2);
        $this->frame = $split[0];
        $this->value = $split[1];
    }

	// Vrací XML reprezentaci proměnné
    public function toXML(int $argNum = 0) : string{
        return "\t\t<arg$argNum type=\"var\">" .
                    "$this->frame@" . htmlspecialchars($this->value, ENT_XML1, 'UTF-8') . 
                "</arg$argNum>";
    }

	// Pokusí se vytvořit objekt Variable zadané hodnoty
    public static function Create(string $var) : ?Variable{
        if(self::IsValid($var)) return new Variable($var);
        else return null;
    }

	// Ověřuje, zda se jedná o platnou proměnnou
    protected static function IsValid(string $var) : bool{
        $split = explode("@", $var, 2);
        return (bool) preg_match("/^[LGT]F$/", $split[0])
               && (bool) preg_match("/^[(a-z)_\-\$&%\*!?][(a-z)(0-9)_\-\$&%\*!?]*$/i", $split[1]);
    }
}

// Třída reprezentující konstanty v IPPcode23
class Constant extends Symbol{
    public string $dataType;

    private function __construct(string $const){
        $split = explode("@", $const, 2);
        $this->dataType = $split[0];
        $this->value = $split[1];
    }

	// Pokusí se vytvořit objekt Constant zadané hodnoty
    public static function Create(string $const) : ?Constant{
        if(self::IsValid($const)) return new Constant($const);
        else return null;
    }

	//  Vrací XML reprezentaci konstanty	
    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"$this->dataType\">" . 
                    htmlspecialchars($this->value, ENT_XML1, 'UTF-8') . 
                "</arg$argNum>";
    }

	// Ověřuje, zda se jedná o platnou konstantu
	protected static function IsValid(string $const) : bool{
		// rozdělí konstantu na typ a hodnotu
        $split = explode("@", $const, 2);
        if(count($split) < 2) return false;
        $value = $split[1];
        $type = $split[0];
        switch($type){
            case "int":
                // dekadické/oktalové nebo hexadecimalní číslo
				return (bool) preg_match("/^[+-]?([1-9][0-9]*(_[0-9]+)*|0)$/i", $value) ||
						(bool) preg_match("/^[+-]?0[xX][0-9a-f]+(_[0-9a-f]+)*$/i", $value) ||
						(bool) preg_match("/^[+-]?0[oO]?[0-7]+(_[0-7]+)*$/i", $value);
            case "bool":
                return $split[1] === "true" || $split[1] === "false";
            case "string":
                // zkontroluje, zda řetězec neobsahuje neplatné escape sekvence
                return (bool) preg_match("/^([^\\\\]|\\\\\d{3})*$/", $split[1]);
            case "nil":
                return $split[1] === "nil";
            default:
                return false;
        } 
    }   
} // class Constant

// Třída reprezentující návěští v IPPcode23
class Label extends Argument{

	// Pokusí se vytvořit objekt Label zadané hodnoty
    public static function Create($label) : ?Label{
       if(Label::isValid($label)) return new Label($label);
       else return null;
    }

	// Ověřuje, zda se jedná o platné návěští
    protected static function isValid(string $label) : bool{
        return (bool) preg_match("/^[(a-z)_\-\$&%\*!?][(a-z)(0-9)_\-\$&%\*!?]*$/i", $label);
    }

	// Vrací XML reprezentaci návěští
    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"label\">" . htmlspecialchars($this->value, ENT_XML1, 'UTF-8'). "</arg$argNum>";
    } 
}

// Třída reprezentující typy v IPPcode23
class Type extends Argument{
	// Pokusí se vytvořit objekt Type zadané hodnoty
    public static function Create($type) : ?Type{
        if(Type::isValid($type)) return new Type($type);
        else return null;
     }
	
	// Ověřuje, zda se jedná o platný typ
    protected static function isValid(string $type) : bool{
        return $type == "int" || $type == "bool" || $type == "string";        
    }

	// Vrací XML reprezentaci typu
    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"type\">$this->value</arg$argNum>";
    }
}



// MAIN
ini_set('display_errors', 'stderr');
$requestedStatistics = array();


// Zpracování argumentů
for($i = 1; $i < $argc; $i++){
    if($argv[$i] == "--help"){
        if($argc > 2){
			fwrite(STDERR, "Přepínač --help nemůže být kombinován s ostatními přepínači!\n");
            exit(10);
        }
        else{
			print("Analyzátor kódu IPPcode23\nProgram načte ze standardního vstupu zdrojový kód v IPPcode23, zkontroluje lexikální a syntaktickou správnost kódu a vypíše\nna standardní výstup XML reprezentaci programu.\n
Parametry:\n
--help - vypíše tuto nápovědu.\n
--stats=<file> - Vypíše požadované statistiky do souboru file.\n
Podporované statistiky:\n
--loc - Počet řádků s instrukcemi.\n
--comments - Počet řádků na kterých se nachází komentář.\n
--labels - Počet definovaných návěští.\n
--jumps - Počet instrukcí skoku, volání a návratu z volání.\n
--fwjumps - Počet dopředných skoků.\n
--backjump - Počet zpětných skoků.\n
--badjumps - Počet skoků na nedefinovaná návěští.\n
--frequent - Seznam nejčastějí použitých insrukcí.\n
--print=<string> - Vypíše do souboru řetězec string.\n
--eol - Vypíše do souboru znak konce řádku.\n	
");
            exit(0);
        }
    }

    if(preg_match("/^\-\-stats=.+$/", $argv[$i])){
        $split = explode("=", $argv[$i], 2);
        $fileName = $split[1];
        if(isset($requestedStatistics[$fileName])){
            fwrite(STDERR, "Nelze zapisovat více skupin statistik do stejného souboru ($fileName)\n");
            exit(12);
        }
        $requestedStatistics[$fileName] = array();
        $i++;
        while($i < $argc){
            if(preg_match("/^\-\-stats=.+$/", $argv[$i])){
                $i--;
                break;
            }
            else{
                if(preg_match("/^\-\-/", $argv[$i])){
                    // odstraní "--" z argumentu
                    $stat = substr($argv[$i], 2);
                    array_push($requestedStatistics[$fileName], $stat);
                }
                else{
                    fwrite(STDERR, "Neznámý přepínač: " . $argv[$i] . "\nNápověda: --help" . "\n");
                    exit(10);
                }
            }
            $i++;
        }
    }
    else{
        fwrite(STDERR, "Neznámý nebo chybně použitý přepínač: " . $argv[$i] . "\n" . "Nápověda: --help\n");
        exit(10);
    }
}



$parser = new Parser();

// Zpracování vstupu
if($parser->ParseFile('php://stdin')){
    print($parser->GenerateOutput());
}
else{
    fwrite(STDERR, $parser->errorMessage);
    exit($parser->errorCode);
}

if(!empty($requestedStatistics)){
	$statsCollector = new StatisticsCollector($parser);
	// Získání požadovaných statistik
	foreach($requestedStatistics as $fileName => $statistics){
		$file = fopen($fileName, "w");
		$statisticsString = $statsCollector->GetStatistics($statistics);
		if($statisticsString === null){
			fwrite(STDERR, $statsCollector->errorMessage);
			exit($statsCollector->errorCode);
		}
		else{
			fwrite($file, $statisticsString);
		}
	}
}
?>
