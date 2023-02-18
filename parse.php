<?php

// StatisticsCollector objects can generate various statistics from a list of instructions
class StatisticsCollector{
    public string $errorMessage;
    public int $errorCode;
    public int $commentCount;
    public array $instructions;

    // stores all defined labels
    private ?array $labels;
    // stores all jump instructions and their order
    private ?array $jumps;

    // takes a Parser object as argument and uses its parsed instructions array and comment count
    public function __construct(Parser $parser){
        $this->instructions = $parser->instructions;
        $this->commentCount = $parser->commentCount;
        $this->labels = null;
        $this->jumps = null;
    }

    // get jump and label instructions and their order and store it in the $labels and $jumps arrays
    // this is here so that other instructions which use these arrays dont have to create these arrays
    // multiple times
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

    // returns the number of comments in the code
    public function GetCommentCount() : int{
        return $this->commentCount;
    }

    // returns the number of instructions in the code
    public function GetInstructionCount() : int{
        return count($this->instructions);
    }

    // returns the number of unique label definitions
    public function GetLabelCount() : int{
        $labels = array();
        foreach($this->instructions as $inst){
            if($inst->opcode === "LABEL"){
                $labels[$inst->args[0]->value] = true;
            }
        }
        return count($labels);
    }

    // returns the number of occurences of all $opcodes (aggregated)
    public function GetOpcodesCount(string ...$opcodes){
        $count = 0;
        foreach($this->instructions as $inst){
            foreach($opcodes as $opcode){
                if($inst->opcode === strtoupper($opcode)) $count++;
            }
        }
        return $count;
    }

    // Gets the most frequently used opcodes and returns them in an array. Returns an empty array if there are no instrucions.
    public function GetMostFrequentOpcodes() : array{
        $opcodes = array();
        foreach($this->instructions as $inst){
            if(isset($opcodes[$inst->opcode])) $opcodes[$inst->opcode]++;
            else $opcodes[$inst->opcode] = 1;
        }
        $opcodeCounts = array_values($opcodes);
        if(!$opcodeCounts) return array();
        // get the maximum value from the $opcodes array and search all keys which have this value
        // then add them to the array
        $max = max(array_values($opcodes));
        $maxOpcodes = array_keys($opcodes, $max);
        return $maxOpcodes;
    }

    // Calculates the number of jumps to non-existent labels
    public function GetBadJumpsCount() : int{
        if($this->labels === null || $this->jumps === null) $this->GetLabelsAndJumps();
        $badJumps = 0;
        foreach($this->jumps as $jumpOrder => $jumpLabel){
            $labelOrder = array_search($jumpLabel, $this->labels);
            if($labelOrder === false) $badJumps++;
        }
        return $badJumps;
    }

    // Calculates the number of forward jumps
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

    // Calculates the number of backward jumps
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

    // Returns a string containing the statistics specified by the parameter
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
                        $this->errorMessage = "Unknown statistic: $request\n" . "Use --help for usage information\n";
                        return null;
                    }
            } // switch($request)
        } // foreach($requestedStatistics as $request)
        return $collectedStatisticsString;
    } // GetStatistics()
} // class StatisticsCollector

// Parser object takes care of checking the syntax of the input file and generating the XML representation.
class Parser{
    // constant containing the correct syntax for IPPcode23 instructions
    public const INSTRUCTIONS = array(
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

    // stores the error code in case parsing fails
    public int $errorCode = 0;
    // stores the error message in case parsing fails
    public string $errorMessage = "";
    // stores the number of the current line being parsed for debugging purposes
    public int $currentLineNumber = 0;
    // stores all Instruction objects in the parsed program
    public array $instructions = array();
    
    public int $commentCount = 0;

    public function __construct(){}

    // parses the file specified by $filePath, checks the syntax and stores the parsed code
    // as an array of Instruction objects
    // returns true if parsing is successfull and false if it fails
    public function ParseFile(string $filePath) : bool{
        $head = false;
        $file = fopen($filePath, 'r');
        if(!$file){
            $this->errorMessage = "Unable to open file $filePath\n";
            $this->errorCode = 11;
            return false;
        }
        // skips any empty or commented lines before the head
        while(($line = fgets($file))){
            $this->currentLineNumber++;
            $trimmed = trim($line);
            if($trimmed == '') continue; // skip whitespace-only lines
            // skip commented lines
            else if(preg_match("/^#.*/", $trimmed)){
                $this->commentCount++;
                continue; 
            }
            else{
                if(strpos($trimmed, "#")) $this->commentCount++;
                // check if the first non-empty non-commented line is head
                if(preg_match("/^(\.ippcode23)(\s)*((#+.*)*)$/i",$trimmed)) $head = true;
                break;
            }
        }

        if(!$head){
            $this->errorMessage = "Missing head!\n";
            $this->errorCode = 21;
            return false;
        }

        // if head was found, parse the rest of input
        while(($line = fgets($file))){
            $this->currentLineNumber++;
            // find the position of # (comment start) on the line
            $commentPos = strpos($line, "#");
            // if # is present, remove everything after it from the line
            if($commentPos !== false){
                $this->commentCount++;
                $line = substr($line, 0, -(strlen($line)-$commentPos));
            }
            // trim leading and trailing whitespace on the line, skip empty lines
            $line = trim($line);
            if($line == '') continue;

            $inst = $this->ParseInstruction($line);
            if(!$inst) return false;
            else array_push($this->instructions, $inst);
        }
        return true;
    } // ParseFile()

    // check instruction syntax and return and Instruction object, or null if parsing fails
    private function ParseInstruction(string $instruction) : ?Instruction{
        // split the line into array
        $lineSplit = preg_split("/(\s)+/", $instruction);

        // lexical check
        if(!preg_match("/[a-z]/i", $lineSplit[0])) {
            print("Invalid opcode on line $this->currentLineNumber\n"); exit(23);
        }

        $opcode = strtoupper($lineSplit[0]);
        // check if instruction exists and get its correct syntax
        $syntax = self::INSTRUCTIONS[strtoupper($lineSplit[0])] ?? false;
        if($syntax === false){
            $this->errorCode = 22;
            $this->errorMessage = "Invalid opcode on line $this->currentLineNumber\n";
            return null;
        }

        
        $args = array_slice($lineSplit, 1);
        $argCount = count($args);
        $argObjectsArray = array();

        if($argCount > count($syntax)) {
            $this->errorMessage = "Too many arguments on line $this->currentLineNumber\n";
            $this->errorCode = 23;
            return null;
        }

        if($argCount < count($syntax)){
            $this->errorMessage = "Too few arguments on line $this->currentLineNumber\n";
            $this->errorCode = 23;
            return null;
        }
        
        else{
            // create Argument objects
            for($i = 0; $i < $argCount; $i++){
                $argObject = null;
                $argType = $syntax[$i];
                $argObject = ArgumentFactory::CreateArgument($argType, $args[$i]);
                if($argObject === null){
                    $this->errorCode = 23;
                    $this->errorMessage = "Wrong type of argument number " . $i+1 . " on line $this->currentLineNumber\n";
                    return null;
                }
                else array_push($argObjectsArray, $argObject);
            }
        } 
        return new Instruction($opcode, $argObjectsArray);
    } // ParseInstruction()

    // Returns the XML representation of the instructions
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

// Parent class of IPPcode23 instructions and arguments
abstract class CodeElement{
    // returns a string containing the XML representation of the concrete element
    public abstract function toXML() : string;
}

// Factory class that creates different subclasses of Argument
class ArgumentFactory{
    // Try to create an Argument of the specified $type
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

// Class representing IPPcode23 instructions
class Instruction extends CodeElement{
    public static int $instCount = 1;
    public string $opcode;
    public array $args; // array of objects of type Argument
    public int $order;

    public function __construct(string $opcode, $args){
        $this->opcode = $opcode;
        $this->args = $args;
        $this->order = Instruction::$instCount++;
    }

    public function toXML() : string{
        // iterate through the arguments and get their XML
        $argXML = "\n";
        $argCount = count($this->args);
        for($i = 1; $i <= $argCount; $i++){
            $argXML .= $this->args[$i-1]->toXML($i);
            $argXML .= "\n";
        }
        // return the instruction xml with arguments as children
        return "\t<instruction opcode=\"$this->opcode\" order=\"$this->order\">$argXML\t</instruction>\n";
    }
}

// Base abstract class for all types of instruction arguments
abstract class Argument extends CodeElement{
    public $value;

    // Factory method for creating objects derived from Argument
    protected abstract static function Create(string $value) : ?Argument ;

    // Abstract method for checking the validity of arguments
    protected abstract static function IsValid(string $value) : bool;

    protected function __construct($value){
        $this->value = $value;
    }
}

// Abstract class derived from Argument, base class for all types of symbols
abstract class Symbol extends Argument{
    // $type represents the first part of the symbol before "@"
    public string $type;
    public function __construct($symbol){
        $split = explode("@", $symbol, 2);
        $this->type = $split[0];
        $this->value = $split[1];
    }

    public static function Create(string $symb) : ?Symbol{
        $symbObject = Variable::Create($symb);
        if($symbObject === null) $symbObject = Constant::Create($symb);
        return $symbObject;
    }

    protected static function IsValid(string $symb) : bool{
        return Variable::IsValid($symb) || Constant::IsValid($symb);
    }
}

// Class representing IPPcode23 variables
class Variable extends Symbol{
    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"var\">" .
                    "$this->type@" . htmlspecialchars($this->value, ENT_XML1, 'UTF-8') . 
                "</arg$argNum>";
    }

    public static function Create(string $var) : ?Variable{
        if(self::IsValid($var)) return new Variable($var);
        else return null;
    }

    // checks if $var is a valid variable
    protected static function IsValid(string $var) : bool{
        $split = explode("@", $var, 2);
        return preg_match("/^[LGT]F$/", $split[0])
               && preg_match("/^[(a-z)_\-\$&%\*!?][(a-z)(0-9)_\-\$&%\*!?]*$/i", $split[1]);
    }
}

// Class representing IPPcode23 constants
class Constant extends Symbol{
    public static function Create(string $const) : ?Constant{
        if(self::IsValid($const)) return new Constant($const);
        else return null;
    }

    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"$this->type\">" . 
                    htmlspecialchars($this->value, ENT_XML1, 'UTF-8') . 
                "</arg$argNum>";
    }

    // check if $const is a valid constant
    protected static function IsValid(string $const) : bool{
        // split constant into array with @ as the separator, if no @ is present constant is invalid
        $split = explode("@", $const, 2);
        if(count($split) < 2) return false;
        $value = $split[1];
        $type = $split[0];
        switch($type){
            case "int":
                return preg_match("/^[+-]*(\d)+$/", $value);
            case "bool":
                return $split[1] === "true" || $split[1] === "false";
            case "string":
                // check if string doesnt contain invalid escape sequences
                return preg_match("/^([^\\\\]|\\\\\d{3})*$/", $split[1]);
            case "nil":
                return $split[1] === "nil";
            default:
                return false;
        } 
    }   
} // class Constant

// Class representing IPPcode23 labels
class Label extends Argument{
    public static function Create($label) : ?Label{
       if(Label::isValid($label)) return new Label($label);
       else return null;
    }

    public static function isValid(string $label) : bool{
        return preg_match("/^[(a-z)_\-\$&%\*!?][(a-z)(0-9)_\-\$&%\*!?]*$/i", $label);
    }

    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"label\">" . htmlspecialchars($this->value, ENT_XML1, 'UTF-8'). "</arg$argNum>";
    } 
}

// Class representing IPPcode23 types
class Type extends Argument{
    public static function Create($type) : ?Type{
        if(Type::isValid($type)) return new Type($type);
        else return null;
     }

    public static function isValid(string $type) : bool{
        return $type == "int" || $type == "bool" || $type == "string";        
    }

    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"type\">$this->value</arg$argNum>";
    }
}

$parser = new Parser();

if($parser->ParseFile('php://stdin')){
    print($parser->GenerateOutput());
}
else{
    fwrite(STDERR, $parser->errorMessage);
    exit($parser->errorCode);
}

$statsCollector = new StatisticsCollector($parser);
$requestedStatistics = array();


for($i = 1; $i < $argc; $i++){
    if($argv[$i] == "--help"){
        if($argc > 2){
            fwrite(STDERR, "--help cannot be used with any other parameters!\n");
            exit(10);
        }
        else{
            print("This is help - TODO\n");
            exit(0);
        }
    }

    if(preg_match("/^\-\-stats=/", $argv[$i])){
        $split = explode("=", $argv[$i], 2);
        $fileName = $split[1];
        if(isset($requestedStatistics[$fileName])){
            fwrite(STDERR, "Cannot write more than one group of statistics to the same file! ($fileName)\n");
            exit(12);
        }
        $requestedStatistics[$fileName] = array();
        $i++;
        while($i < $argc){
            if(preg_match("/^\-\-stats=/", $argv[$i])){
                $i--;
                break;
            }
            else{
                if(preg_match("/^\-\-/", $argv[$i])){
                    // remove the "--"
                    $stat = substr($argv[$i], 2);
                    array_push($requestedStatistics[$fileName], $stat);
                }
                else{
                    fwrite(STDERR, "Unknown parameter: " . $argv[$i] . "\n" . "Use --help for usage information\n");
                    exit(10);
                }
            }
            $i++;
        }
    }
    else{
        fwrite(STDERR, "Unknown or incorrectly used parameter: " . $argv[$i] . "\n" . "Use --help for usage information\n");
        exit(10);
    }
}

// MAIN
ini_set('display_errors', 'stderr');
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

?>
