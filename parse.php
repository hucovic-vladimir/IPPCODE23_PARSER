<?php

// This class provides statistics about the parsed code
class StatisticsCollector{
    public int $commentCount;
    public array $instructions;

    private ?array $labels;
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
    private function GetLabelsAndJumps(){
        $instCount = count($this->instructions);
        $this->labels = array();
        $this->jumps = array();

        for($i = 0; $i < $instCount; $i++){
            if($this->instructions[$i]->opcode === "LABEL"){
                $this->labels[$i] = $this->instructions[$i]->args[0];
            }

            if($this->instructions[$i]->opcode === "JUMP" || $this->instructions[$i]->opcode === "JUMPIFEQ" ||
                $this->instructions[$i]->opcode === "JUMPIFNEQ" || $this->instructions[$i]->opcode === "CALL"){
                $this->jumps[$i] = $this->instructions[$i]->args[0];
            }
        }
    }

    // returns the number of comments in the code
    public function GetCommentCount(){
        return $this->commentCount;
    }

    // returns the number of instructions in the code
    public function GetInstructionCount(){
        return count($this->instructions);
    }

    // returns the number of unique label definitions
    public function GetLabelCount(){
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
            $labelOrder = array_search($jumpLabel, $this->labels);
            if($labelOrder !== false && $labelOrder > $jumpOrder) $fwJumps++;
        }
        return $fwJumps;
    }

    // Calculates the number of backward jumps
    public function GetBackwardJumpsCount() : int{
        if(!$this->labels || !$this->jumps) $this->GetLabelsAndJumps();
        $backJumps = 0;
        foreach($this->jumps as $jumpOrder => $jumpLabel){
            $labelOrder = array_search($jumpLabel, $this->labels);
            if($labelOrder !== false && $labelOrder < $jumpOrder) $backJumps++;
        }
        return $backJumps;
    }
}


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
    // Contains all Instructions in the parsed program
    public array $instructions = array();
    
    public int $commentCount = 0;

    // private constructor
    public function __construct(){}

    // returns the symbol type of $symb or unknown if it is an invalid symbol
    private function IdentifySymbol(string $symb) : string {
        if($this->IsValidVariable($symb)) return "var";
        if($this->IsValidConstant($symb)) return "const";
        else return "unknown";
    }

    // checks if $var is a valid ippcode23 variable
    private function IsValidVariable(string $var){
        $split = explode("@", $var, 2);
        if(preg_match("/^[LGT]F$/", $split[0]) && preg_match("/^[(a-z)_\-\$&%\*!?][(a-z)(0-9)_\-\$&%\*!?]*$/i", $split[1])){
            return true;
        }
        else return false;
    }

    // 
    private function IsValidConstant(string $const){
        // split constant into array with @ as the separator, if no @ is present constant is invalid
        $split = explode("@", $const, 2);
        if(count($split) < 2) return false;
        $value = $split[1];
        $type = $split[0];
        switch($type){
            case "int":
                return preg_match("/^[+-]*(\d)+$/", $value);
            case "bool":
                return $split[1] == "true" || $split[1] == "false";
            case "string":
                // check if string doesnt contain invalid escape sequences
                return checkEscapeSequences($value);
            case "nil":
                return $split[1] == "nil";
            default:
                return false;
        } 
    }

    public function IsValidType(string $type){
        return $type == "int" || $type == "bool" || $type == "string";        
    }

    public function IsValidLabel(string $label){
        return preg_match("/^[(a-z)_\-\$&%\*!?][(a-z)(0-9)_\-\$&%\*!?]*$/i", $label);
    }

    
    public function ParseFile(string $filePath) : bool{
        $head = false;
        $file = fopen($filePath, 'r');
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
                // first uncommented non-empty line must be head, otherwise return false with error 21
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
    }

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
                switch($syntax[$i]){
                    case "symb" : $argObject = Argument::Create($this->IdentifySymbol($args[$i]), $args[$i]);
                    break;
                    case "label":
                        if($this->IsValidLabel($args[$i])) $argObject = Argument::Create("label", $args[$i]);
                    break;
                    case "var": 
                        if($this->IsValidVariable($args[$i])) $argObject = Argument::Create("var", $args[$i]);
                    break;
                    case "const": 
                        if($this->IsValidConstant($args[$i])) $argObject = Argument::Create("const", $args[$i]);
                    case "type":
                        if($this->IsValidType($args[$i])) $argObject = Argument::Create("type", $args[$i]);    
                    break;
                }

                if($argObject === null){
                    $this->errorCode = 23;
                    $this->errorMessage = "Wrong type of argument number " . $i+1 . " on line $this->currentLineNumber\n";
                    return null;
                }
                else array_push($argObjectsArray, $argObject);
            }
        }
        return new Instruction($opcode, $argObjectsArray);
    }

    public function GenerateOutput() : string{
        $output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $output .= "<program language=\"IPPcode23\">\n";
        
        foreach($this->instructions as $instruction){
            $output .= $instruction->toXML();
        }

        $output .= "</program>\n";

        return $output;
        
    }
}

// Parent class of IPPcode23 instructions and arguments
abstract class CodeElement{
    // returns a string containing the XML representation of the concrete element
    public abstract function toXML() : string;
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
    public static function Create(string $type, string $value) : ?Argument{
        switch($type){
            case "var":
                return new Variable($value);
            case "const":
                return new Constant($value);
            case "label":
                return new Label($value);
            case "type":
                return new Type($value);
            default: 
                return null;               
        }
    }

    protected function __construct($value){
        $this->value = $value;
    }    
}

// Abstract class derived from Argument, base class for all types of symbols
abstract class Symbol extends Argument{
    // $type represents the first part of the symbol before "@"
    public string $type;
    protected function __construct($symbol){
        $split = explode("@", $symbol, 2);
        $this->type = $split[0];
        $this->value = $split[1];
    }
}

// Class representing IPPcode23 variables
class Variable extends Symbol{
    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"var\">" .
                    "$this->type@" . htmlspecialchars($this->value, ENT_XML1, 'UTF-8') . 
                "</arg$argNum>";
    }
}

// Class representing IPPcode23 constants
class Constant extends Symbol{
    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"$this->type\">" . 
                    htmlspecialchars($this->value, ENT_XML1, 'UTF-8') . 
                "</arg$argNum>";
    }
}

// Class representing IPPcode23 labels
class Label extends Argument{
    public static function isValid(string $label) : bool{
        return preg_match("/^[(a-z)_\-\$&%\*!?][(a-z)(0-9)_\-\$&%\*!?]*$/i", $label);
    }

    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"label\">" . htmlspecialchars($this->value, ENT_XML1, 'UTF-8'). "</arg$argNum>";
    }
}

// Class representing IPPcode23 types
class Type extends Argument{
    public static function isValid(string $type) : bool{
        return $type == "int" || $type == "bool" || $type == "string";        
    }

    public function toXML($argNum = 0) : string{
        return "\t\t<arg$argNum type=\"type\">$this->value</arg$argNum>";
    }
}

// checks if escape sequences in $string are valid
// if there are no escape sequences or they are all valid, return true
// if there is an invalid escape sequence, return false
function checkEscapeSequences($string){
    $valid = true;
    $len = strlen($string);

    for($i = 0; $i < $len; $i++){
        if($string[$i] === "\\"){
            if($i+3 >= $len) return false;
            for($j = 1; $j < 4; $j++){
                if(!is_numeric($string[$i+$j])) return false;
            }
        }
    }
    return true;
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
$statsCollector->instructions = $parser->instructions;
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

function writeStatistics(array $requestedStatistics, StatisticsCollector $statsCollector){
    foreach($requestedStatistics as $fileName => $requests){
        $file = fopen($fileName, "w");
        if(!$file) {
            fwrite(STDERR, "File $fileName cannot be opened/created!\n");
            exit(12);
        }
        foreach($requests as $request){
            switch($request){
                case "loc": fwrite($file, $statsCollector->GetInstructionCount() . "\n"); break;
                case "comments": fwrite($file, $statsCollector->GetCommentCount() . "\n"); break;
                case "labels": fwrite($file, $statsCollector->GetLabelCount() . "\n"); break;
                case "jumps": fwrite($file, $statsCollector->GetOpcodesCount("jump", "jumpifeq", "jumpifneq", "call", "return") 
                                . "\n"); break;
                case "fwjumps": fwrite($file, $statsCollector->GetForwardJumpsCount() . "\n"); break;
                case "backjumps": fwrite($file, $statsCollector->GetBackwardJumpsCount() . "\n"); break;
                case "badjumps": fwrite($file, $statsCollector->GetBadJumpsCount(). "\n"); break;
                case "eol": fwrite($file, "\n"); break;
                case "frequent": 
                    $mostFrequent = $statsCollector->GetMostFrequentOpcodes();
                    for($i = 0; $i < count($mostFrequent); $i++){
                        fwrite($file, $mostFrequent[$i]);
                        if($i + 1 != count($mostFrequent)) fwrite($file, ",");
                    }
                    fwrite($file, "\n");
                    break;
                default:
                    if(preg_match("/^print=/", $request)){
                        $split = explode("=", $request, 2);
                        fwrite($file, $split[1]);
                    }
                    else{
                        fwrite(STDERR, "Unknown statistic: $request\n" . "Use --help for usage\n");
                    }
            }
        }
    }
}

// MAIN
ini_set('display_errors', 'stderr');

writeStatistics($requestedStatistics, $statsCollector);

?>
