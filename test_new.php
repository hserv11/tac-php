<?php
require_once '/home/leo/phpAVT-new/phpsa/vendor/autoload.php';
require 'vendor/autoload.php';
use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\NodeDumper;
use PhpParser\{Node, NodeTraverser, NodeVisitorAbstract};
class ThreeAddressCodeGenerator extends PhpParser\NodeVisitorAbstract {
    private $tempVarCounter = 0;
    private $threeAddressCode;
    private $labelCounter = 0;
    private $printer;
    private $processedNodes;

    public function __construct(){
        //parent::__construct();
        $this->printer = new PhpParser\PrettyPrinter\Standard();
        $this->threeAddressCode = [];
        $this->processedNodes = new \SplObjectStorage();
    }

    // 创建一个临时变量
    private function createTempVar() {
        return '_t' . $this->tempVarCounter++;
    }
    // 添加一个帮助函数来处理表达式
    private function handleExpr(PhpParser\Node\Expr $expr=null) {
        
        if ($expr === null) {
            return '';
        }
        if ($expr instanceof PhpParser\Node\Expr\Assign) {
            $this->processedNodes->attach($expr);
            $varName = $this->handleExpr($expr->var);
            $exprName = $this->handleExpr($expr->expr);
            return $varName . " = " . $exprName;
        } elseif ($expr instanceof PhpParser\Node\Expr\Variable) {
            $this->processedNodes->attach($expr);
            // 处理变量
            return "$" . $expr->name;
        } elseif ($expr instanceof PhpParser\Node\Expr\BinaryOp) {
            $this->processedNodes->attach($expr);
            // 处理二元运算符
            $left = $this->handleExpr($expr->left);
            $right = $this->handleExpr($expr->right);
            return $left . " " . $this->getOperator($expr) . " " . $right;
        } elseif ($expr instanceof PhpParser\Node\Scalar\String_) {
            $this->processedNodes->attach($expr);
            // 处理字符串
            return "'" . $expr->value . "'";
        } elseif ($expr instanceof PhpParser\Node\Expr\UnaryOp) {
            $this->processedNodes->attach($expr);
            // $tempVar = $this->createTempVar();
            // $tempVar = $tempVar . " = " . $expr->getOperatorSigil() . " " . $this->handleExpr($expr->expr) . "\n";
            // return $tempVar;
            return $expr->getOperatorSigil() . " " . $this->handleExpr($expr->expr);
        } elseif ($expr instanceof PhpParser\Node\Expr\FuncCall) {
            $this->processedNodes->attach($expr);
            $argVars = [];
            foreach ($expr->args as $arg) {
                $argVars[] = $this->handleExpr($arg->value);
            }
            //$tempVar = $this->createTempVar();
            return $expr->name . "(" . implode(", ", $argVars) . ")";
            //return $tempVar;
        } elseif ($expr instanceof PhpParser\Node\Expr\MethodCall) {
            $this->processedNodes->attach($expr);
            $objectVar = $this->handleExpr($expr->var);
            $argVars = [];
            foreach ($expr->args as $arg) {
                $argVars[] = $this->handleExpr($arg->value);
            }
            $tempVar = $this->createTempVar();
            $tempVar = $objectVar . "." . $expr->name->name . "(" . implode(", ", $argVars) . ")";
            return $tempVar;
        } elseif ($expr instanceof PhpParser\Node\Expr\PropertyFetch) {
            $this->processedNodes->attach($expr);
            // $tempVar = $this->createTempVar();
            // $tempVar = $tempVar . " = " . $this->handleExpr($expr->var) . "->" . $expr->name->name . "\n";
            // return $tempVar;
            return $this->handleExpr($expr->var) . "->" . $expr->name->name;
        } elseif ($expr instanceof PhpParser\Node\Expr\New_) {
            $this->processedNodes->attach($expr);
            // 处理新对象的实例化
            // return 'new ' . $expr->class->toString();
            $className = $this->handleName($expr->class);
            $args = [];
            foreach ($expr->args as $arg) {
                $args[] = $this->handleExpr($arg->value);
            }
            return "new " . $className . "(" . implode(", ", $args) . ")";
        } elseif ($expr instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            $this->processedNodes->attach($expr);
            // 处理数组元素获取
            $var = $this->handleExpr($expr->var);
            $dim = $this->handleExpr($expr->dim);
            return $var . '[' . $dim . ']';
        } elseif ($expr instanceof PhpParser\Node\Expr\BooleanNot) {
            $this->processedNodes->attach($expr);
            // 处理布尔取反操作
            $expr = $this->handleExpr($expr->expr);
            return '!' . $expr;
        } elseif ($expr instanceof PhpParser\Node\Scalar\Encapsed) {
            $this->processedNodes->attach($expr);
            // 处理字符串插值
            // return implode("", array_map([$this, "handleExpr"], $expr->parts));
            $parts = array_map(function ($part) {
                if ($part instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
                    return $part->value;
                } else {
                    return $this->handleExpr($part);
                }
            }, $expr->parts);
            return implode('', $parts);
        } elseif ($expr instanceof PhpParser\Node\Expr\ClassConstFetch){
            $this->processedNodes->attach($expr);
            // Assume that $this->handleName can convert a Name node to a string.
            $className = $this->handleName($expr->class);
            return "{$className}::{$expr->name}";
        } elseif ($expr instanceof PhpParser\Node\Expr\ConstFetch){
            $this->processedNodes->attach($expr);
            return $expr->name->toString(); // 常量的名称
        } elseif ($expr instanceof PhpParser\Node\Expr\Empty_){
            $this->processedNodes->attach($expr);
            $exprName = $this->handleExpr($expr->expr);
            return "empty($exprName)";
        } elseif ($expr instanceof PhpParser\Node\Expr\ErrorSuppress) {
            $this->processedNodes->attach($expr);
            // 在 handleExpr() 方法中添加对 PhpParser\Node\Expr\ErrorSuppress 类型的处理
            // 处理 ErrorSuppress 类型的表达式
            // $tempVar = $this->createTempVar();
            $tempVar = "@" . $this->handleExpr($expr->expr);
            return $tempVar;
        } elseif ($expr instanceof PhpParser\Node\Expr\PostInc) {
            $this->processedNodes->attach($expr);
            $var = $this->handleExpr($expr->var);
            return $var . '++';
        } elseif ($expr instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
            $this->processedNodes->attach($expr);
            // 处理字符串插值的一部分
            return $expr->value;
        } elseif ($expr instanceof PhpParser\Node\Expr\Array_) {
            $this->processedNodes->attach($expr);
            $elements = $expr->items;
            $arrayVars = [];
            foreach ($elements as $element) {
                $arrayVars[] = $this->handleExpr($element->value);
            }
            $tempVar = $this->createTempVar();
            $tempVar = $tempVar . " = [" . implode(", ", $arrayVars) . "]";
            return $tempVar;
        } elseif ($expr instanceof PhpParser\Node\Expr\AssignOp\Concat) {
            $this->processedNodes->attach($expr);
            $var = $this->handleExpr($expr->var);
            $expr = $this->handleExpr($expr->expr);
            $tempVar = $this->createTempVar();
            return $tempVar . " = " . $var . " . " . $expr;
        } elseif ($expr instanceof Node\Scalar\LNumber) {
            $this->processedNodes->attach($expr);
            return $expr->value;
        } else {
            throw new Exception("Unsupported expression type: " . get_class($expr));
        }
    }
    public function handleNode(PhpParser\Node $node){
        $code = [];
        if ($node instanceof PhpParser\Node\Stmt\If_) {
            $this->processedNodes->attach($node);
            // 处理 if 语句
            $condition = $this->handleExpr($node->cond);
            $ifStmts = $this->generateStatements($node->stmts);
            $elseStmts = $node->else !== null ? $this->generateStatements($node->else->stmts) : [];    
            $ifLabel = $this->createLabel();
            $elseLabel = $node->else !== null ? $this->createLabel() : null;
            $endLabel = $this->createLabel();    
            $this->handleNode($node)[] = "if " . $condition . " goto " . $ifLabel['start'] . ";";
            $code[] = "goto " . ($elseLabel !== null ? $elseLabel['start'] : $endLabel['start']) . ";";    
            $code[] = $ifLabel['start'] . ":";
            $code = array_merge($code, $ifStmts);
            $code[] = "goto " . $endLabel['start'] . ";";    
            if ($elseLabel !== null) {
                $code[] = $elseLabel['start'] . ":";
                $code = array_merge($code, $elseStmts);
                $code[] = "goto " . $endLabel['start'] . ";";
            }    
            $code[] = $endLabel['start'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\ElseIf_) {
            $this->processedNodes->attach($node);
            $code[] = "if " . $node->cond . " goto " . "Label for elseif branch;\n";
            // Recurse into "elseif" branch
        } elseif ($node instanceof PhpParser\Node\Stmt\Else_) {
            $this->processedNodes->attach($node);
            $code[] = "goto Label for else branch;\n";
            // Recurse into "else" branch
        } elseif ($node instanceof PhpParser\Node\Stmt\While_) {
            $this->processedNodes->attach($node);
            // 处理 while 循环
            $condition = $this->handleExpr($node->cond);
            $stmts = $this->generateStatements($node->stmts);    
            $startLabel = $this->createLabel();
            $loopLabel = $this->createLabel();    
            $code[] = $startLabel['start'] . ":";
            $code[] = "if " . $condition . " goto " . $loopLabel['start'] . ";";
            $code[] = "goto " . $loopLabel['end'] . ";";    
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $code[] = "if " . $condition . " goto " . $startLabel['start'] . ";";
            $code[] = "goto " . $loopLabel['end'] . ";";
            $code[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\For_) {
            $this->processedNodes->attach($node);
            // 处理 for 循环
            $initStmts = $this->generateStatements($node->init);
            $condition = $this->handleExpr($node->cond);
            $loopStmts = $this->generateStatements($node->loop);
            $stmts = $this->generateStatements($node->stmts);
            $startLabel = $this->createLabel();
            $loopLabel = $this->createLabel();
            $code = array_merge($code, $initStmts);
            $code[] = $startLabel['start'] . ":";
            $code[] = "if " . $condition . " goto " . $loopLabel['start'] . ";";
            $code[] = "goto " . $loopLabel['end'] . ";";
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $code = array_merge($code, $loopStmts);
            $code[] = "if " . $condition . " goto " . $startLabel['start'] . ";";
            $code[] = "goto " . $loopLabel['end'] . ";";
            $code[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\Foreach_) {
            $this->processedNodes->attach($node);
            // 处理 Foreach 循环
            $valueVar = $this->handleExpr($node->valueVar);
            $arrayVar = $this->handleExpr($node->expr);
            $stmts = $this->generateStatements($node->stmts);
            $loopLabel = $this->createLabel();
            $code[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $loopLabel['start'] . ";";
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $code[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $loopLabel['start'] . ";";
            $code[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\Class_) {
            $this->processedNodes->attach($node);
            // 处理类定义
            $className = $this->handleName($node->name);
            $code[] = "class " . $className . "{";
            $code = array_merge($code, $this->generateStatements($node->stmts));
            $code[] = "}";
            // $this->threeAddressCode = array_merge($this->threeAddressCode,$code);
        } elseif ($node instanceof PhpParser\Node\Stmt\Function_) {
            $this->processedNodes->attach($node);
            // 处理函数定义
            $functionName = $this->handleName($node->name);
            $code[] = "function " . $functionName;

            $params = [];
            foreach ($node->params as $param) {
                $paramName = $this->handleNode($param);
                $params = array_merge($params,$paramName);
            }
            $code[] = "( " . implode(", ",$params) . " ){"; 


            $code = array_merge($code, $this->generateStatements($node->stmts));

            $code[] = "}";
            //$this->threeAddressCode = array_merge($this->threeAddressCode,$code);//['label' => $functionName, 'code' => $code];
        } elseif ($node instanceof PhpParser\Node\Stmt\Expression) {
            $this->processedNodes->attach($node);
            // 处理表达式语句
            $code[] = $this->handleExpr($node->expr);
        } elseif ($node instanceof PhpParser\Node\Expr\Assign) {
            $this->processedNodes->attach($node);
            // 处理赋值语句
            $var = $this->handleExpr($node->var);
            $value = $this->handleExpr($node->expr);
            $code[] = $var . " = " . $value;
        } elseif ($node instanceof PhpParser\Node\Expr\MethodCall) {
            $this->processedNodes->attach($node);
            // 先处理对象表达式
            $objectVar = $this->handleExpr($node->var);            
            // 然后处理参数列表
            $argVars = [];
            foreach ($node->args as $arg) {
                $argVars[] = $this->handleExpr($arg->value);
            }
            // 进行方法调用
            $tempVar = $this->createTempVar();
            $code[] = $tempVar . " = call " . $objectVar . "." . $node->name->name . "(" . implode(", ", $argVars) . ")";
        } elseif ($node instanceof PhpParser\Node\Expr\FuncCall) {
            $this->processedNodes->attach($node);
            $name = $node->name->toString();
            $args = [];
            foreach ($node->args as $arg) {
                $args[] = $this->handleExpr($arg->value);
            }
            //$tempVar = $this->createTempVar();
            $code[] = $name . "(" . implode(", ", $args) . ")";
        } elseif ($node instanceof PhpParser\Node\Expr\PostInc) {
            $this->processedNodes->attach($node);
            // 处理后自增表达式
            // 可以获取变量信息
            
            $varName = $this->handleExpr($node->var);
            
            // 在这里可以根据需要生成相应的三地址码
            
            // 示例：将后自增语句添加到三地址码数组
            $code[] = $varName . ' = ' . $varName . ' + 1';
        } elseif ($node instanceof PhpParser\Node\Param) {
                // 处理函数或方法的参数
                // 可以根据需要获取参数的名称、默认值等信息
                $paramName = $node->var->name;
                $defaultValue = $node->default ? $this->handleExpr($node->default) : null;
                
                // 在这里可以根据需要生成相应的三地址码
                
                // 示例：将参数名称和默认值添加到三地址码数组
                if($defaultValue==null){
                    $code[] = "$" . $paramName;
                } else {
                    $code[] = "$" . $paramName . ' = ' . $defaultValue;
                }
        }
        return $code; 
    }
    public function enterNode(PhpParser\Node $node) {
        if ($this->processedNodes->contains($node)) {
            return; // 跳过已处理节点
        }
        $code = $this->handleNode($node);
        if($node instanceof PhpParser\Node\Stmt\Expression){
            $code[] = ";\n";
        }
        $this->threeAddressCode = array_merge($this->threeAddressCode,$code);
    }
    public function getThreeAddressCode() {
        return $this->threeAddressCode;
    }
    public function handleName($name)
    {
        if ($name instanceof PhpParser\Node\Identifier) {
            return $name->name;
        } elseif ($name instanceof PhpParser\Node\Name) {
            return $name->getLast();
        }
        return implode('\\', $name->parts);
    }
    
    public function getOperator($operator) {
        switch (get_class($operator)) {
            case PhpParser\Node\Expr\BinaryOp\Plus::class:
                return '+';
            case PhpParser\Node\Expr\BinaryOp\Minus::class:
                return '-';
            case PhpParser\Node\Expr\BinaryOp\Mul::class:
                return '*';
            case PhpParser\Node\Expr\BinaryOp\Div::class:
                return '/';
            // 这里添加更多你需要处理的运算符类型
            case PhpParser\Node\Expr\BinaryOp\Concat::class:
                return '.';
            default:
                throw new Exception("Unsupported operator type: " . get_class($operator));
        }
    }
    private function generateStatements(array $stmts) {
        $code = [];
        $result = [];
        foreach ($stmts as $stmt) {
            
            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                $this->processedNodes->attach($stmt);
                // 处理 if 语句
                $condition = $this->handleExpr($stmt->cond);
                $ifStmts = $this->generateStatements($stmt->stmts);
                $elseStmts = $stmt->else !== null ? $this->generateStatements($stmt->else->stmts) : [];
                $ifLabel = $this->createLabel();
                $elseLabel = $stmt->else !== null ? $this->createLabel() : null;
                $endLabel = $this->createLabel();
                $code[] = "if " . $condition . " goto " . $ifLabel['start'] . ";";
                $code[] = "goto " . ($elseLabel !== null ? $elseLabel['start'] : $endLabel['start']) . ";";
                $code[] = $ifLabel['start'] . ":";
                $code = array_merge($code, $ifStmts);
                $code[] = "goto " . $endLabel['start'] . ";";
                if ($elseLabel !== null) {
                    $code[] = $elseLabel['start'] . ":";
                    $code = array_merge($code, $elseStmts);
                    $code[] = "goto " . $endLabel['start'] . ";";
                }
                $code[] = $endLabel['start'] . ":";
                // $result[] = ['label' => $ifLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
                $this->processedNodes->attach($stmt);
                // 处理 while 循环
                $condition = $this->handleExpr($stmt->cond);
                $stmts = $this->generateStatements($stmt->stmts);
                $startLabel = $this->createLabel();
                $loopLabel = $this->createLabel();
                $code[] = $startLabel['start'] . ":";
                $code[] = "if " . $condition . " goto " . $loopLabel['start'] . ";";
                $code[] = "goto " . $loopLabel['end'] . ";";
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code[] = "if " . $condition . " goto " . $startLabel['start'] . ";";
                $code[] = "goto " . $loopLabel['end'] . ";";
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $startLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
                $this->processedNodes->attach($stmt);
                // 处理 for 循环
                $initStmts = $stmt->init !== null ? $this->generateStatements([$stmt->init]) : [];
                $condition = $stmt->cond !== null ? $this->handleExpr($stmt->cond) : null;
                $loopStmts = $stmt->loop !== null ? $this->generateStatements([$stmt->loop]) : [];
                $stmts = $this->generateStatements($stmt->stmts);
                $startLabel = $this->createLabel();
                $loopLabel = $this->createLabel();
                $code = array_merge($code, $initStmts);
                $code[] = $startLabel['start'] . ":";
                if ($condition !== null) {
                    $code[] = "if " . $condition . " goto " . $loopLabel['start'] . ";";
                    $code[] = "goto " . $loopLabel['end'] . ";";
                } else {
                    $code[] = "goto " . $loopLabel['start'] . ";";
                }   
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code = array_merge($code, $loopStmts);
                if ($condition !== null) {
                    $code[] = "if " . $condition . " goto " . $startLabel['start'] . ";";
                    $code[] = "goto " . $loopLabel['end'] . ";";
                } else {
                    $code[] = "goto " . $startLabel['start'] . ";";
                }
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $startLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Foreach_) {
                $this->processedNodes->attach($stmt);
                // 处理 foreach 循环
                $valueVar = $this->handleExpr($stmt->valueVar);
                $arrayVar = $this->handleExpr($stmt->expr);
                $stmts = $this->generateStatements($stmt->stmts);
                $loopLabel = $this->createLabel();
                $code[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $loopLabel['start'] . ";";  
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $loopLabel['start'] . ";";  
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $loopLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
                $this->processedNodes->attach($stmt);
                    $argVars = [];
                    foreach ($stmt->args as $arg) {
                        $argVars[] = $this->handleExpr($arg->value);
                    }
                    $tempVar = $this->createTempVar();
                    $code[] = $tempVar . " = call " . $stmt->name->name . "(" . implode(", ", $argVars) . ")";
                    // $result[] = ['label' => $tempVar, 'code' => $code];
                    // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                $this->processedNodes->attach($stmt);
                // 处理类定义
                $className = $stmt->name;
                $code[] = "class " . $className . "{";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "}";
                // $result[] = ['label' => $className, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                $this->processedNodes->attach($stmt);
                // 处理函数定义
                $functionName = $stmt->name;
                $code[] = "function " . $functionName;
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_function " . $functionName;
                // $result[] = ['label' => $functionName, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                $this->processedNodes->attach($stmt);
                // 处理异常处理
                $code[] = "try";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                foreach ($stmt->catches as $catch) {
                    $code = array_merge($code, $this->generateStatements([$catch]));
                }
                if ($stmt->finally !== null) {
                    $code = array_merge($code, $this->generateStatements([$stmt->finally->stmts]));
                }
                $code[] = "end_try";
                // $result[] = ['label' => 'try_catch', 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Catch_) {
                $this->processedNodes->attach($stmt);
                // 处理 catch
                $code[] = "catch " . $stmt->varType . " as " . $stmt->var->name;
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_catch";
                // $result[] = ['label' => $stmt->var->name, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Finally_) {
                $this->processedNodes->attach($stmt);
                // 处理 finally
                $code[] = "finally";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_finally";
                // $result[] = ['label' => 'finally', 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Expr\Assign) {
                $this->processedNodes->attach($stmt);
                // 处理赋值语句
                $var = $this->handleExpr($stmt->var);
                $expr = $this->handleExpr($stmt->expr);
                $code[] = $var . " = " . $expr;
                // $result[] = ['label' => $var, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Property) {
                $this->processedNodes->attach($stmt);
                // 处理属性定义
                foreach ($stmt->props as $prop) {
                    $propertyName = $prop->name->name;
                    $defaultValue = $prop->default !== null ? $this->handleExpr($prop->default) : null;
                    $code[] = "var $" . $propertyName . " = " . $defaultValue;
                }
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
                $this->processedNodes->attach($stmt);
                // 处理类方法定义
                $methodName = $stmt->name->name;
                $code[] = "function " . $methodName;
                
                $params = [];
                foreach ($stmt->params as $param) {
                    $paramName = $this->handleNode($param);
                    $params = array_merge($params,$paramName);
                }
                $code[] = "( " . implode(", ",$params) . " ){"; 
    
                $code = array_merge($code, $this->generateStatements($stmt->stmts));
    
                $code[] = "}";
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                $this->processedNodes->attach($stmt);
                $returnValue = $this->handleExpr($stmt->expr);
                $code[] = 'return ' . $returnValue . ";\n";
            } elseif ($stmt instanceof PhpParser\Node\Expr\PostInc) {
                $this->processedNodes->attach($stmt);
                // 处理后自增表达式
                // 可以获取变量信息
                
                $varName = $this->handleExpr($stmt->var);
                
                // 在这里可以根据需要生成相应的三地址码
                
                // 示例：将后自增语句添加到三地址码数组
                $code[] = $varName . ' = ' . $varName . ' + 1';
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression) {
                $this->processedNodes->attach($stmt);
                // 处理表达式语句
                $code[] = $this->handleExpr($stmt->expr);
            }
            if ($stmt instanceof PhpParser\Node\Stmt\Expression){
                $code[] = ";\n";
            }
            $result = array_merge($result, $code);
            $code = [];
        }    
        return $result;
    }   
    private function createLabel() {
        $label = '_L' . $this->labelCounter++;
        return [
            'start' => $label,
            'end' => $label . '_end',
        ];
    }    
    private function createLoopLabel() {
        $labelStart = 'L' . $this->tempVarCounter;
        $labelEnd = 'L' . ($this->tempVarCounter + 1);
        $this->tempVarCounter += 2;
        return ['start' => $labelStart, 'end' => $labelEnd];
    }}
$file_path = '/home/leo/phpAVT-new/code_examples.php';
// 1. 创建解析器
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
try {
    // 2. 从文件读取PHP源代码
    $code = file_get_contents($file_path);
    // 3. 解析源代码
    $stmts = $parser->parse($code);
    // 4. 创建ThreeAddressCodeGenerator
    $threeAddressCodeGenerator = new ThreeAddressCodeGenerator();
    // 5. 遍历抽象语法树，生成三地址码
    //$stmts = $threeAddressCodeGenerator->traverse($stmts);
    $traverser = new NodeTraverser;
    $traverser->addVisitor($threeAddressCodeGenerator);
    #$modifiedStmts = 
    $traverser->traverse($stmts);
    // 6. 输出三地址码
    $final_code = implode("\n", $threeAddressCodeGenerator->getThreeAddressCode());
    $final_code = str_replace("\n;",";",$final_code);
    echo $final_code;
    // echo implode("\n",$modifiedStmts);
} catch (Error $e) {
    echo 'Parse Error: ', $e->getMessage();
}
?>