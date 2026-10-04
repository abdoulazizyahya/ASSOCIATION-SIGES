<?php
/*******************************************************************************
* FPDF                                                                         *
*                                                                              *
* Version: 1.86                                                                *
* Date:    2023-06-25                                                          *
* Author:  Olivier PLATHEY                                                     *
*******************************************************************************/

class FPDF
{
const VERSION = '1.86';
protected $page;               // current page number
protected $n;                  // current object number
protected $offsets;            // array of object offsets
protected $buffer;             // buffer holding in-memory PDF
protected $pages;              // array containing pages
protected $state;              // current document state
protected $compress;           // compression flag
protected $iconv;              // whether iconv is available
protected $k;                  // scale factor (number of points in user unit)
protected $DefOrientation;     // default orientation
protected $CurOrientation;     // current orientation
protected $StdPageSizes;       // standard page sizes
protected $DefPageSize;        // default page size
protected $CurPageSize;        // current page size
protected $CurRotation;        // current page rotation
protected $PageInfo;           // page-related data
protected $wPt, $hPt;          // dimensions of current page in points
protected $w, $h;              // dimensions of current page in user unit
protected $lMargin;            // left margin
protected $tMargin;            // top margin
protected $rMargin;            // right margin
protected $bMargin;            // page break margin
protected $cMargin;            // cell margin
protected $x, $y;              // current position in user unit
protected $lasth;              // height of last printed cell
protected $LineWidth;          // line width in user unit
protected $fontpath;           // directory containing fonts
protected $CoreFonts;          // array of core font names
protected $fonts;              // array of used fonts
protected $FontFiles;          // array of font files
protected $encodings;          // array of encodings
protected $cmaps;              // array of ToUnicode CMaps
protected $FontFamily;         // current font family
protected $FontStyle;          // current font style
protected $underline;          // underlining flag
protected $CurrentFont;        // current font info
protected $FontSizePt;         // current font size in points
protected $FontSize;           // current font size in user unit
protected $DrawColor;          // commands for drawing color
protected $FillColor;          // commands for filling color
protected $TextColor;          // commands for text color
protected $ColorFlag;          // indicates whether fill and text colors are different
protected $WithAlpha;          // indicates whether alpha channel is used
protected $ws;                 // word spacing
protected $images;             // array of used images
protected $PageLinks;          // array of links in pages
protected $links;              // array of internal links
protected $AutoPageBreak;      // automatic page breaking
protected $PageBreakTrigger;   // threshold used to trigger page breaks
protected $InHeader;           // flag set when processing header
protected $InFooter;           // flag set when processing footer
protected $AliasNbPages;       // alias for total number of pages
protected $ZoomMode;           // zoom display mode
protected $LayoutMode;         // layout display mode
protected $metadata;           // document properties
protected $CreationDate;       // document creation date
protected $PDFVersion;         // PDF version number
public $tableStartX = null;
protected $tmpImages = []; // pour stocker les fichiers temporaires PNG
protected $extgstates = [];


/*******************************************************************************
*                               Public methods                                 *
*******************************************************************************/

function __construct($orientation='P', $unit='mm', $size='A4')
{
	// Initialization of properties
	$this->state = 0;
	$this->page = 0;
	$this->n = 2;
	$this->buffer = '';
	$this->pages = array();
	$this->PageInfo = array();
	$this->fonts = array();
	$this->FontFiles = array();
	$this->encodings = array();
	$this->cmaps = array();
	$this->images = array();
	$this->links = array();
	$this->InHeader = false;
	$this->InFooter = false;
	$this->lasth = 0;
	$this->FontFamily = '';
	$this->FontStyle = '';
	$this->FontSizePt = 12;
	$this->underline = false;
	$this->DrawColor = '0 G';
	$this->FillColor = '0 g';
	$this->TextColor = '0 g';
	$this->ColorFlag = false;
	$this->WithAlpha = false;
	$this->ws = 0;
	$this->iconv = function_exists('iconv');
	// Font path
	if(defined('FPDF_FONTPATH'))
		$this->fontpath = FPDF_FONTPATH;
	else
		$this->fontpath = dirname(__FILE__).'/font/';
	// Core fonts
	$this->CoreFonts = array('courier', 'helvetica', 'times', 'symbol', 'zapfdingbats');
	// Scale factor
	if($unit=='pt')
		$this->k = 1;
	elseif($unit=='mm')
		$this->k = 72/25.4;
	elseif($unit=='cm')
		$this->k = 72/2.54;
	elseif($unit=='in')
		$this->k = 72;
	else
		$this->Error('Incorrect unit: '.$unit);
	// Page sizes
	$this->StdPageSizes = array('a3'=>array(841.89,1190.55), 'a4'=>array(595.28,841.89), 'a5'=>array(420.94,595.28),
		'letter'=>array(612,792), 'legal'=>array(612,1008));
	$size = $this->_getpagesize($size);
	$this->DefPageSize = $size;
	$this->CurPageSize = $size;
	// Page orientation
	$orientation = strtolower($orientation);
	if($orientation=='p' || $orientation=='portrait')
	{
		$this->DefOrientation = 'P';
		$this->w = $size[0];
		$this->h = $size[1];
	}
	elseif($orientation=='l' || $orientation=='landscape')
	{
		$this->DefOrientation = 'L';
		$this->w = $size[1];
		$this->h = $size[0];
	}
	else
		$this->Error('Incorrect orientation: '.$orientation);
	$this->CurOrientation = $this->DefOrientation;
	$this->wPt = $this->w*$this->k;
	$this->hPt = $this->h*$this->k;
	// Page rotation
	$this->CurRotation = 0;
	// Page margins (1 cm)
	$margin = 28.35/$this->k;
	$this->SetMargins($margin,$margin);
	// Interior cell margin (1 mm)
	$this->cMargin = $margin/10;
	// Line width (0.2 mm)
	$this->LineWidth = .567/$this->k;
	// Automatic page break
	$this->SetAutoPageBreak(true,2*$margin);
	// Default display mode
	$this->SetDisplayMode('default');
	// Enable compression
	$this->SetCompression(true);
	// Metadata
	$this->metadata = array('Producer'=>'FPDF '.self::VERSION);
	// Set default PDF version number
	$this->PDFVersion = '1.3';
}

function SetMargins($left, $top, $right=null)
{
	// Set left, top and right margins
	$this->lMargin = $left;
	$this->tMargin = $top;
	if($right===null)
		$right = $left;
	$this->rMargin = $right;
}

function SetLeftMargin($margin)
{
	// Set left margin
	$this->lMargin = $margin;
	if($this->page>0 && $this->x<$margin)
		$this->x = $margin;
}

function SetTopMargin($margin)
{
	// Set top margin
	$this->tMargin = $margin;
}

function SetRightMargin($margin)
{
	// Set right margin
	$this->rMargin = $margin;
}

function SetAutoPageBreak($auto, $margin=0)
{
	// Set auto page break mode and triggering margin
	$this->AutoPageBreak = $auto;
	$this->bMargin = $margin;
	$this->PageBreakTrigger = $this->h-$margin;
}

function SetDisplayMode($zoom, $layout='default')
{
	// Set display mode in viewer
	if($zoom=='fullpage' || $zoom=='fullwidth' || $zoom=='real' || $zoom=='default' || !is_string($zoom))
		$this->ZoomMode = $zoom;
	else
		$this->Error('Incorrect zoom display mode: '.$zoom);
	if($layout=='single' || $layout=='continuous' || $layout=='two' || $layout=='default')
		$this->LayoutMode = $layout;
	else
		$this->Error('Incorrect layout display mode: '.$layout);
}

function SetCompression($compress)
{
	// Set page compression
	if(function_exists('gzcompress'))
		$this->compress = $compress;
	else
		$this->compress = false;
}

function SetTitle($title, $isUTF8=false)
{
	// Title of document
	$this->metadata['Title'] = $isUTF8 ? $title : $this->_UTF8encode($title);
}

function SetAuthor($author, $isUTF8=false)
{
	// Author of document
	$this->metadata['Author'] = $isUTF8 ? $author : $this->_UTF8encode($author);
}

function SetSubject($subject, $isUTF8=false)
{
	// Subject of document
	$this->metadata['Subject'] = $isUTF8 ? $subject : $this->_UTF8encode($subject);
}

function SetKeywords($keywords, $isUTF8=false)
{
	// Keywords of document
	$this->metadata['Keywords'] = $isUTF8 ? $keywords : $this->_UTF8encode($keywords);
}

function SetCreator($creator, $isUTF8=false)
{
	// Creator of document
	$this->metadata['Creator'] = $isUTF8 ? $creator : $this->_UTF8encode($creator);
}

function AliasNbPages($alias='{nb}')
{
	// Define an alias for total number of pages
	$this->AliasNbPages = $alias;
}

function Error($msg)
{
	// Fatal error
	throw new Exception('FPDF error: '.$msg);
}

function Close()
{
	// Terminate document
	if($this->state==3)
		return;
	if($this->page==0)
		$this->AddPage();
	// Page footer
	$this->InFooter = true;
	$this->Footer();
	$this->InFooter = false;
	// Close page
	$this->_endpage();
	// Close document
	$this->_enddoc();
}

function AddPage($orientation='', $size='', $rotation=0)
{
	// Start a new page
	if($this->state==3)
		$this->Error('The document is closed');
	$family = $this->FontFamily;
	$style = $this->FontStyle.($this->underline ? 'U' : '');
	$fontsize = $this->FontSizePt;
	$lw = $this->LineWidth;
	$dc = $this->DrawColor;
	$fc = $this->FillColor;
	$tc = $this->TextColor;
	$cf = $this->ColorFlag;
	if($this->page>0)
	{
		// Page footer
		$this->InFooter = true;
		$this->Footer();
		$this->InFooter = false;
		// Close page
		$this->_endpage();
	}
	// Start new page
	$this->_beginpage($orientation,$size,$rotation);
	// Set line cap style to square
	$this->_out('2 J');
	// Set line width
	$this->LineWidth = $lw;
	$this->_out(sprintf('%.2F w',$lw*$this->k));
	// Set font
	if($family)
		$this->SetFont($family,$style,$fontsize);
	// Set colors
	$this->DrawColor = $dc;
	if($dc!='0 G')
		$this->_out($dc);
	$this->FillColor = $fc;
	if($fc!='0 g')
		$this->_out($fc);
	$this->TextColor = $tc;
	$this->ColorFlag = $cf;
	// Page header
	$this->InHeader = true;
	$this->Header();
	$this->InHeader = false;
	// Restore line width
	if($this->LineWidth!=$lw)
	{
		$this->LineWidth = $lw;
		$this->_out(sprintf('%.2F w',$lw*$this->k));
	}
	// Restore font
	if($family)
		$this->SetFont($family,$style,$fontsize);
	// Restore colors
	if($this->DrawColor!=$dc)
	{
		$this->DrawColor = $dc;
		$this->_out($dc);
	}
	if($this->FillColor!=$fc)
	{
		$this->FillColor = $fc;
		$this->_out($fc);
	}
	$this->TextColor = $tc;
	$this->ColorFlag = $cf;
}

function Header()
{
	// To be implemented in your own inherited class
}

function Footer()
{
	// To be implemented in your own inherited class
}

function PageNo()
{
	// Get current page number
	return $this->page;
}

function SetDrawColor($r, $g=null, $b=null)
{
	// Set color for all stroking operations
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->DrawColor = sprintf('%.3F G',$r/255);
	else
		$this->DrawColor = sprintf('%.3F %.3F %.3F RG',$r/255,$g/255,$b/255);
	if($this->page>0)
		$this->_out($this->DrawColor);
}

function SetFillColor($r, $g=null, $b=null)
{
	// Set color for all filling operations
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->FillColor = sprintf('%.3F g',$r/255);
	else
		$this->FillColor = sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
	$this->ColorFlag = ($this->FillColor!=$this->TextColor);
	if($this->page>0)
		$this->_out($this->FillColor);
}

function SetTextColor($r, $g=null, $b=null)
{
	// Set color for text
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->TextColor = sprintf('%.3F g',$r/255);
	else
		$this->TextColor = sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
	$this->ColorFlag = ($this->FillColor!=$this->TextColor);
}

function GetStringWidth($s)
{
	// Get width of a string in the current font
	$cw = $this->CurrentFont['cw'];
	$w = 0;
	$s = (string)$s;
	$l = strlen($s);
	for($i=0;$i<$l;$i++)
		$w += $cw[$s[$i]];
	return $w*$this->FontSize/1000;
}

function SetLineWidth($width)
{
	// Set line width
	$this->LineWidth = $width;
	if($this->page>0)
		$this->_out(sprintf('%.2F w',$width*$this->k));
}

function Line($x1, $y1, $x2, $y2)
{
	// Draw a line
	$this->_out(sprintf('%.2F %.2F m %.2F %.2F l S',$x1*$this->k,($this->h-$y1)*$this->k,$x2*$this->k,($this->h-$y2)*$this->k));
}

function Rect($x, $y, $w, $h, $style='')
{
	// Draw a rectangle
	if($style=='F')
		$op = 'f';
	elseif($style=='FD' || $style=='DF')
		$op = 'B';
	else
		$op = 'S';
	$this->_out(sprintf('%.2F %.2F %.2F %.2F re %s',$x*$this->k,($this->h-$y)*$this->k,$w*$this->k,-$h*$this->k,$op));
}

function AddFont($family, $style='', $file='', $dir='')
{
	// Add a TrueType, OpenType or Type1 font
	$family = strtolower($family);
	if($file=='')
		$file = str_replace(' ','',$family).strtolower($style).'.php';
	$style = strtoupper($style);
	if($style=='IB')
		$style = 'BI';
	$fontkey = $family.$style;
	if(isset($this->fonts[$fontkey]))
		return;
	if(strpos($file,'/')!==false || strpos($file,"\\")!==false)
		$this->Error('Incorrect font definition file name: '.$file);
	if($dir=='')
		$dir = $this->fontpath;
	if(substr($dir,-1)!='/' && substr($dir,-1)!='\\')
		$dir .= '/';
	$info = $this->_loadfont($dir.$file);
	$info['i'] = count($this->fonts)+1;
	if(!empty($info['file']))
	{
		// Embedded font
		$info['file'] = $dir.$info['file'];
		if($info['type']=='TrueType')
			$this->FontFiles[$info['file']] = array('length1'=>$info['originalsize']);
		else
			$this->FontFiles[$info['file']] = array('length1'=>$info['size1'], 'length2'=>$info['size2']);
	}
	$this->fonts[$fontkey] = $info;
}

function SetFont($family, $style='', $size=0)
{
	// Select a font; size given in points
	if($family=='')
		$family = $this->FontFamily;
	else
		$family = strtolower($family);
	$style = strtoupper($style);
	if(strpos($style,'U')!==false)
	{
		$this->underline = true;
		$style = str_replace('U','',$style);
	}
	else
		$this->underline = false;
	if($style=='IB')
		$style = 'BI';
	if($size==0)
		$size = $this->FontSizePt;
	// Test if font is already selected
	if($this->FontFamily==$family && $this->FontStyle==$style && $this->FontSizePt==$size)
		return;
	// Test if font is already loaded
	$fontkey = $family.$style;
	if(!isset($this->fonts[$fontkey]))
	{
		// Test if one of the core fonts
		if($family=='arial')
			$family = 'helvetica';
		if(in_array($family,$this->CoreFonts))
		{
			if($family=='symbol' || $family=='zapfdingbats')
				$style = '';
			$fontkey = $family.$style;
			if(!isset($this->fonts[$fontkey]))
				$this->AddFont($family,$style);
		}
		else
			$this->Error('Undefined font: '.$family.' '.$style);
	}
	// Select it
	$this->FontFamily = $family;
	$this->FontStyle = $style;
	$this->FontSizePt = $size;
	$this->FontSize = $size/$this->k;
	$this->CurrentFont = $this->fonts[$fontkey];
	if($this->page>0)
		$this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
}

function SetFontSize($size)
{
	// Set font size in points
	if($this->FontSizePt==$size)
		return;
	$this->FontSizePt = $size;
	$this->FontSize = $size/$this->k;
	if($this->page>0 && isset($this->CurrentFont))
		$this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
}

function AddLink()
{
	// Create a new internal link
	$n = count($this->links)+1;
	$this->links[$n] = array(0, 0);
	return $n;
}

function SetLink($link, $y=0, $page=-1)
{
	// Set destination of internal link
	if($y==-1)
		$y = $this->y;
	if($page==-1)
		$page = $this->page;
	$this->links[$link] = array($page, $y);
}

function Link($x, $y, $w, $h, $link)
{
	// Put a link on the page
	$this->PageLinks[$this->page][] = array($x*$this->k, $this->hPt-$y*$this->k, $w*$this->k, $h*$this->k, $link);
}

function Text($x, $y, $txt)
{
	// Output a string
	if(!isset($this->CurrentFont))
		$this->Error('No font has been set');
	$txt = (string)$txt;
	$s = sprintf('BT %.2F %.2F Td (%s) Tj ET',$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
	if($this->underline && $txt!=='')
		$s .= ' '.$this->_dounderline($x,$y,$txt);
	if($this->ColorFlag)
		$s = 'q '.$this->TextColor.' '.$s.' Q';
	$this->_out($s);
}

function AcceptPageBreak()
{
	// Accept automatic page break or not
	return $this->AutoPageBreak;
}

function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')
{
	// Output a cell
	$k = $this->k;
	if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak())
	{
		// Automatic page break
		$x = $this->x;
		$ws = $this->ws;
		if($ws>0)
		{
			$this->ws = 0;
			$this->_out('0 Tw');
		}
		$this->AddPage($this->CurOrientation,$this->CurPageSize,$this->CurRotation);
		$this->x = $x;
		if($ws>0)
		{
			$this->ws = $ws;
			$this->_out(sprintf('%.3F Tw',$ws*$k));
		}
	}
	if($w==0)
		$w = $this->w-$this->rMargin-$this->x;
	$s = '';
	if($fill || $border==1)
	{
		if($fill)
			$op = ($border==1) ? 'B' : 'f';
		else
			$op = 'S';
		$s = sprintf('%.2F %.2F %.2F %.2F re %s ',$this->x*$k,($this->h-$this->y)*$k,$w*$k,-$h*$k,$op);
	}
	if(is_string($border))
	{
		$x = $this->x;
		$y = $this->y;
		if(strpos($border,'L')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,$x*$k,($this->h-($y+$h))*$k);
		if(strpos($border,'T')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-$y)*$k);
		if(strpos($border,'R')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ',($x+$w)*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
		if(strpos($border,'B')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-($y+$h))*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
	}
	$txt = (string)$txt;
	if($txt!=='')
	{
		if(!isset($this->CurrentFont))
			$this->Error('No font has been set');
		if($align=='R')
			$dx = $w-$this->cMargin-$this->GetStringWidth($txt);
		elseif($align=='C')
			$dx = ($w-$this->GetStringWidth($txt))/2;
		else
			$dx = $this->cMargin;
		if($this->ColorFlag)
			$s .= 'q '.$this->TextColor.' ';
		$s .= sprintf('BT %.2F %.2F Td (%s) Tj ET',($this->x+$dx)*$k,($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k,$this->_escape($txt));
		if($this->underline)
			$s .= ' '.$this->_dounderline($this->x+$dx,$this->y+.5*$h+.3*$this->FontSize,$txt);
		if($this->ColorFlag)
			$s .= ' Q';
		if($link)
			$this->Link($this->x+$dx,$this->y+.5*$h-.5*$this->FontSize,$this->GetStringWidth($txt),$this->FontSize,$link);
	}
	if($s)
		$this->_out($s);
	$this->lasth = $h;
	if($ln>0)
	{
		// Go to next line
		$this->y += $h;
		if($ln==1)
			$this->x = $this->lMargin;
	}
	else
		$this->x += $w;
}

function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false)
{
	// Output text with automatic or explicit line breaks
	if(!isset($this->CurrentFont))
		$this->Error('No font has been set');
	$cw = $this->CurrentFont['cw'];
	if($w==0)
		$w = $this->w-$this->rMargin-$this->x;
	$wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
	$s = str_replace("\r",'',(string)$txt);
	$nb = strlen($s);
	if($nb>0 && $s[$nb-1]=="\n")
		$nb--;
	$b = 0;
	if($border)
	{
		if($border==1)
		{
			$border = 'LTRB';
			$b = 'LRT';
			$b2 = 'LR';
		}
		else
		{
			$b2 = '';
			if(strpos($border,'L')!==false)
				$b2 .= 'L';
			if(strpos($border,'R')!==false)
				$b2 .= 'R';
			$b = (strpos($border,'T')!==false) ? $b2.'T' : $b2;
		}
	}
	$sep = -1;
	$i = 0;
	$j = 0;
	$l = 0;
	$ns = 0;
	$nl = 1;
	while($i<$nb)
	{
		// Get next character
		$c = $s[$i];
		if($c=="\n")
		{
			// Explicit line break
			if($this->ws>0)
			{
				$this->ws = 0;
				$this->_out('0 Tw');
			}
			$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
			$i++;
			$sep = -1;
			$j = $i;
			$l = 0;
			$ns = 0;
			$nl++;
			if($border && $nl==2)
				$b = $b2;
			continue;
		}
		if($c==' ')
		{
			$sep = $i;
			$ls = $l;
			$ns++;
		}
		$l += $cw[$c];
		if($l>$wmax)
		{
			// Automatic line break
			if($sep==-1)
			{
				if($i==$j)
					$i++;
				if($this->ws>0)
				{
					$this->ws = 0;
					$this->_out('0 Tw');
				}
				$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
			}
			else
			{
				if($align=='J')
				{
					$this->ws = ($ns>1) ? ($wmax-$ls)/1000*$this->FontSize/($ns-1) : 0;
					$this->_out(sprintf('%.3F Tw',$this->ws*$this->k));
				}
				$this->Cell($w,$h,substr($s,$j,$sep-$j),$b,2,$align,$fill);
				$i = $sep+1;
			}
			$sep = -1;
			$j = $i;
			$l = 0;
			$ns = 0;
			$nl++;
			if($border && $nl==2)
				$b = $b2;
		}
		else
			$i++;
	}
	// Last chunk
	if($this->ws>0)
	{
		$this->ws = 0;
		$this->_out('0 Tw');
	}
	if($border && strpos($border,'B')!==false)
		$b .= 'B';
	$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
	$this->x = $this->lMargin;
}

function Write($h, $txt, $link='')
{
	// Output text in flowing mode
	if(!isset($this->CurrentFont))
		$this->Error('No font has been set');
	$cw = $this->CurrentFont['cw'];
	$w = $this->w-$this->rMargin-$this->x;
	$wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
	$s = str_replace("\r",'',(string)$txt);
	$nb = strlen($s);
	$sep = -1;
	$i = 0;
	$j = 0;
	$l = 0;
	$nl = 1;
	while($i<$nb)
	{
		// Get next character
		$c = $s[$i];
		if($c=="\n")
		{
			// Explicit line break
			$this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',false,$link);
			$i++;
			$sep = -1;
			$j = $i;
			$l = 0;
			if($nl==1)
			{
				$this->x = $this->lMargin;
				$w = $this->w-$this->rMargin-$this->x;
				$wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
			}
			$nl++;
			continue;
		}
		if($c==' ')
			$sep = $i;
		$l += $cw[$c];
		if($l>$wmax)
		{
			// Automatic line break
			if($sep==-1)
			{
				if($this->x>$this->lMargin)
				{
					// Move to next line
					$this->x = $this->lMargin;
					$this->y += $h;
					$w = $this->w-$this->rMargin-$this->x;
					$wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
					$i++;
					$nl++;
					continue;
				}
				if($i==$j)
					$i++;
				$this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',false,$link);
			}
			else
			{
				$this->Cell($w,$h,substr($s,$j,$sep-$j),0,2,'',false,$link);
				$i = $sep+1;
			}
			$sep = -1;
			$j = $i;
			$l = 0;
			if($nl==1)
			{
				$this->x = $this->lMargin;
				$w = $this->w-$this->rMargin-$this->x;
				$wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
			}
			$nl++;
		}
		else
			$i++;
	}
	// Last chunk
	if($i!=$j)
		$this->Cell($l/1000*$this->FontSize,$h,substr($s,$j),0,0,'',false,$link);
}

function Ln($h=null)
{
	// Line feed; default value is the last cell height
	$this->x = $this->lMargin;
	if($h===null)
		$this->y += $this->lasth;
	else
		$this->y += $h;
}


function Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')
{
    // V�rifier si le fichier est PNG
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        $img = @imagecreatefrompng($file);
        if ($img !== false) {
            // D�sactiver l�entrelacement
            if (function_exists('imageinterlace')) {
                imageinterlace($img, false);
            }
            // Pr�server le canal alpha (transparence) lors de la r��criture :
            // sans ces deux appels, GD aplatit toute zone transparente en noir
            // opaque au moment de l'imagepng() ci-dessous (bug constat�� avec
            // les signatures num��riques en PNG transparent, qui ressortaient
            // sur fond noir dans le PDF).
            imagealphablending($img, false);
            imagesavealpha($img, true);
            // Cr�er un fichier temporaire PNG non interlaced
            $tmpFile = tempnam(sys_get_temp_dir(), 'fpdf_png_').'.png';
            imagepng($img, $tmpFile, 9);
            imagedestroy($img);
            $file = $tmpFile;
            $this->tmpImages[] = $tmpFile; // garder pour nettoyage
        }
    }

    // --- reste du code original ---
    if($file=='')
        $this->Error('Image file name is empty');
    if(!isset($this->images[$file]))
    {
        if($type=='')
        {
            $pos = strrpos($file,'.');
            if(!$pos)
                $this->Error('Image file has no extension and no type was specified: '.$file);
            $type = substr($file,$pos+1);
        }
        $type = strtolower($type);
        if($type=='jpeg')
            $type = 'jpg';
        $mtd = '_parse'.$type;
        if(!method_exists($this,$mtd))
            $this->Error('Unsupported image type: '.$type);
        $info = $this->$mtd($file);
        $info['i'] = count($this->images)+1;
        $this->images[$file] = $info;
    }
    else
        $info = $this->images[$file];

    if($w==0 && $h==0) {
        $w = -96;
        $h = -96;
    }
    if($w<0)
        $w = -$info['w']*72/$w/$this->k;
    if($h<0)
        $h = -$info['h']*72/$h/$this->k;
    if($w==0)
        $w = $h*$info['w']/$info['h'];
    if($h==0)
        $h = $w*$info['h']/$info['w'];

    if($y===null) {
        if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
            $x2 = $this->x;
            $this->AddPage($this->CurOrientation,$this->CurPageSize,$this->CurRotation);
            $this->x = $x2;
        }
        $y = $this->y;
        $this->y += $h;
    }

    if($x===null)
        $x = $this->x;
    $this->_out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q',
        $w*$this->k,$h*$this->k,$x*$this->k,($this->h-($y+$h))*$this->k,$info['i']));
    if($link)
        $this->Link($x,$y,$w,$h,$link);
}

function GetPageWidth()
{
	// Get current page width
	return $this->w;
}

function GetPageHeight()
{
	// Get current page height
	return $this->h;
}

function GetX()
{
	// Get x position
	return $this->x;
}

function SetX($x)
{
	// Set x position
	if($x>=0)
		$this->x = $x;
	else
		$this->x = $this->w+$x;
}

function GetY()
{
	// Get y position
	return $this->y;
}

function SetY($y, $resetX=true)
{
	// Set y position and optionally reset x
	if($y>=0)
		$this->y = $y;
	else
		$this->y = $this->h+$y;
	if($resetX)
		$this->x = $this->lMargin;
}

function SetXY($x, $y)
{
	// Set x and y positions
	$this->SetX($x);
	$this->SetY($y,false);
}

function Output($dest='', $name='', $isUTF8=false)
{
	// Output PDF to some destination
	$this->Close();
	if(strlen($name)==1 && strlen($dest)!=1)
	{
		// Fix parameter order
		$tmp = $dest;
		$dest = $name;
		$name = $tmp;
	}
	if($dest=='')
		$dest = 'I';
	if($name=='')
		$name = 'doc.pdf';
	switch(strtoupper($dest))
	{
		case 'I':
			// Send to standard output
			$this->_checkoutput();
			if(PHP_SAPI!='cli')
			{
				// We send to a browser
				header('Content-Type: application/pdf');
				header('Content-Disposition: inline; '.$this->_httpencode('filename',$name,$isUTF8));
				header('Cache-Control: private, max-age=0, must-revalidate');
				header('Pragma: public');
			}
			echo $this->buffer;
			break;
		case 'D':
			// Download file
			$this->_checkoutput();
			header('Content-Type: application/pdf');
			header('Content-Disposition: attachment; '.$this->_httpencode('filename',$name,$isUTF8));
			header('Cache-Control: private, max-age=0, must-revalidate');
			header('Pragma: public');
			echo $this->buffer;
			break;
		case 'F':
			// Save to local file
			if(!file_put_contents($name,$this->buffer))
				$this->Error('Unable to create output file: '.$name);
			break;
		case 'S':
			// Return as a string
			return $this->buffer;
		default:
			$this->Error('Incorrect output destination: '.$dest);
	}
	// Nettoyage des fichiers temporaires (apr�s tous les cas)
    if (isset($this->tmpImages) && is_array($this->tmpImages)) {
        foreach ($this->tmpImages as $tmp) {
            if (file_exists($tmp)) unlink($tmp);
        }
    }
	
	return '';
}

/*******************************************************************************
*                              Protected methods                               *
*******************************************************************************/

protected function _checkoutput()
{
	if(PHP_SAPI!='cli')
	{
		if(headers_sent($file,$line))
			$this->Error("Some data has already been output, can't send PDF file (output started at $file:$line)");
	}
	if(ob_get_length())
	{
		// The output buffer is not empty
		if(preg_match('/^(\xEF\xBB\xBF)?\s*$/',ob_get_contents()))
		{
			// It contains only a UTF-8 BOM and/or whitespace, let's clean it
			ob_clean();
		}
		else
			$this->Error("Some data has already been output, can't send PDF file");
	}
}

protected function _getpagesize($size)
{
	if(is_string($size))
	{
		$size = strtolower($size);
		if(!isset($this->StdPageSizes[$size]))
			$this->Error('Unknown page size: '.$size);
		$a = $this->StdPageSizes[$size];
		return array($a[0]/$this->k, $a[1]/$this->k);
	}
	else
	{
		if($size[0]>$size[1])
			return array($size[1], $size[0]);
		else
			return $size;
	}
}

protected function _beginpage($orientation, $size, $rotation)
{
	$this->page++;
	$this->pages[$this->page] = '';
	$this->PageLinks[$this->page] = array();
	$this->state = 2;
	$this->x = $this->lMargin;
	$this->y = $this->tMargin;
	$this->FontFamily = '';
	// Check page size and orientation
	if($orientation=='')
		$orientation = $this->DefOrientation;
	else
		$orientation = strtoupper($orientation[0]);
	if($size=='')
		$size = $this->DefPageSize;
	else
		$size = $this->_getpagesize($size);
	if($orientation!=$this->CurOrientation || $size[0]!=$this->CurPageSize[0] || $size[1]!=$this->CurPageSize[1])
	{
		// New size or orientation
		if($orientation=='P')
		{
			$this->w = $size[0];
			$this->h = $size[1];
		}
		else
		{
			$this->w = $size[1];
			$this->h = $size[0];
		}
		$this->wPt = $this->w*$this->k;
		$this->hPt = $this->h*$this->k;
		$this->PageBreakTrigger = $this->h-$this->bMargin;
		$this->CurOrientation = $orientation;
		$this->CurPageSize = $size;
	}
	if($orientation!=$this->DefOrientation || $size[0]!=$this->DefPageSize[0] || $size[1]!=$this->DefPageSize[1])
		$this->PageInfo[$this->page]['size'] = array($this->wPt, $this->hPt);
	if($rotation!=0)
	{
		if($rotation%90!=0)
			$this->Error('Incorrect rotation value: '.$rotation);
		$this->PageInfo[$this->page]['rotation'] = $rotation;
	}
	$this->CurRotation = $rotation;
}

protected function _endpage()
{
	$this->state = 1;
}

protected function _loadfont($path)
{
	// Load a font definition file
	include($path);
	if(!isset($name))
		$this->Error('Could not include font definition file: '.$path);
	if(isset($enc))
		$enc = strtolower($enc);
	if(!isset($subsetted))
		$subsetted = false;
	return get_defined_vars();
}

protected function _isascii($s)
{
	// Test if string is ASCII
	$nb = strlen($s);
	for($i=0;$i<$nb;$i++)
	{
		if(ord($s[$i])>127)
			return false;
	}
	return true;
}

protected function _httpencode($param, $value, $isUTF8)
{
	// Encode HTTP header field parameter
	if($this->_isascii($value))
		return $param.'="'.$value.'"';
	if(!$isUTF8)
		$value = $this->_UTF8encode($value);
	return $param."*=UTF-8''".rawurlencode($value);
}

protected function _UTF8encode($s)
{
	// Convert ISO-8859-1 to UTF-8
	if($this->iconv)
		return iconv('ISO-8859-1','UTF-8',$s);
	$res = '';
	$nb = strlen($s);
	for($i=0;$i<$nb;$i++)
	{
		$c = $s[$i];
		$v = ord($c);
		if($v>=128)
		{
			$res .= chr(0xC0 | ($v >> 6));
			$res .= chr(0x80 | ($v & 0x3F));
		}
		else
			$res .= $c;
	}
	return $res;
}

protected function _UTF8toUTF16($s)
{
	// Convert UTF-8 to UTF-16BE with BOM
	$res = "\xFE\xFF";
	if($this->iconv)
		return $res.iconv('UTF-8','UTF-16BE',$s);
	$nb = strlen($s);
	$i = 0;
	while($i<$nb)
	{
		$c1 = ord($s[$i++]);
		if($c1>=224)
		{
			// 3-byte character
			$c2 = ord($s[$i++]);
			$c3 = ord($s[$i++]);
			$res .= chr((($c1 & 0x0F)<<4) + (($c2 & 0x3C)>>2));
			$res .= chr((($c2 & 0x03)<<6) + ($c3 & 0x3F));
		}
		elseif($c1>=192)
		{
			// 2-byte character
			$c2 = ord($s[$i++]);
			$res .= chr(($c1 & 0x1C)>>2);
			$res .= chr((($c1 & 0x03)<<6) + ($c2 & 0x3F));
		}
		else
		{
			// Single-byte character
			$res .= "\0".chr($c1);
		}
	}
	return $res;
}

protected function _escape($s)
{
	// Escape special characters
	if(strpos($s,'(')!==false || strpos($s,')')!==false || strpos($s,'\\')!==false || strpos($s,"\r")!==false)
		return str_replace(array('\\','(',')',"\r"), array('\\\\','\\(','\\)','\\r'), $s);
	else
		return $s;
}

protected function _textstring($s)
{
	// Format a text string
	if(!$this->_isascii($s))
		$s = $this->_UTF8toUTF16($s);
	return '('.$this->_escape($s).')';
}

protected function _dounderline($x, $y, $txt)
{
	// Underline text
	$up = $this->CurrentFont['up'];
	$ut = $this->CurrentFont['ut'];
	$w = $this->GetStringWidth($txt)+$this->ws*substr_count($txt,' ');
	return sprintf('%.2F %.2F %.2F %.2F re f',$x*$this->k,($this->h-($y-$up/1000*$this->FontSize))*$this->k,$w*$this->k,-$ut/1000*$this->FontSizePt);
}

protected function _parsejpg($file)
{
	// Extract info from a JPEG file
	$a = getimagesize($file);
	if(!$a)
		$this->Error('Missing or incorrect image file: '.$file);
	if($a[2]!=2)
		$this->Error('Not a JPEG file: '.$file);
	if(!isset($a['channels']) || $a['channels']==3)
		$colspace = 'DeviceRGB';
	elseif($a['channels']==4)
		$colspace = 'DeviceCMYK';
	else
		$colspace = 'DeviceGray';
	$bpc = isset($a['bits']) ? $a['bits'] : 8;
	$data = file_get_contents($file);
	return array('w'=>$a[0], 'h'=>$a[1], 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'DCTDecode', 'data'=>$data);
}

protected function _parsepng($file)
{
	// Extract info from a PNG file
	$f = fopen($file,'rb');
	if(!$f)
		$this->Error('Can\'t open image file: '.$file);
	$info = $this->_parsepngstream($f,$file);
	fclose($f);
	return $info;
}

protected function _parsepngstream($f, $file)
{
	// Check signature
	if($this->_readstream($f,8)!=chr(137).'PNG'.chr(13).chr(10).chr(26).chr(10))
		$this->Error('Not a PNG file: '.$file);

	// Read header chunk
	$this->_readstream($f,4);
	if($this->_readstream($f,4)!='IHDR')
		$this->Error('Incorrect PNG file: '.$file);
	$w = $this->_readint($f);
	$h = $this->_readint($f);
	$bpc = ord($this->_readstream($f,1));
	if($bpc>8)
		$this->Error('16-bit depth not supported: '.$file);
	$ct = ord($this->_readstream($f,1));
	if($ct==0 || $ct==4)
		$colspace = 'DeviceGray';
	elseif($ct==2 || $ct==6)
		$colspace = 'DeviceRGB';
	elseif($ct==3)
		$colspace = 'Indexed';
	else
		$this->Error('Unknown color type: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Unknown compression method: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Unknown filter method: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Interlacing not supported: '.$file);
	$this->_readstream($f,4);
	$dp = '/Predictor 15 /Colors '.($colspace=='DeviceRGB' ? 3 : 1).' /BitsPerComponent '.$bpc.' /Columns '.$w;

	// Scan chunks looking for palette, transparency and image data
	$pal = '';
	$trns = '';
	$data = '';
	do
	{
		$n = $this->_readint($f);
		$type = $this->_readstream($f,4);
		if($type=='PLTE')
		{
			// Read palette
			$pal = $this->_readstream($f,$n);
			$this->_readstream($f,4);
		}
		elseif($type=='tRNS')
		{
			// Read transparency info
			$t = $this->_readstream($f,$n);
			if($ct==0)
				$trns = array(ord(substr($t,1,1)));
			elseif($ct==2)
				$trns = array(ord(substr($t,1,1)), ord(substr($t,3,1)), ord(substr($t,5,1)));
			else
			{
				$pos = strpos($t,chr(0));
				if($pos!==false)
					$trns = array($pos);
			}
			$this->_readstream($f,4);
		}
		elseif($type=='IDAT')
		{
			// Read image data block
			$data .= $this->_readstream($f,$n);
			$this->_readstream($f,4);
		}
		elseif($type=='IEND')
			break;
		else
			$this->_readstream($f,$n+4);
	}
	while($n);

	if($colspace=='Indexed' && empty($pal))
		$this->Error('Missing palette in '.$file);
	$info = array('w'=>$w, 'h'=>$h, 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'FlateDecode', 'dp'=>$dp, 'pal'=>$pal, 'trns'=>$trns);
	if($ct>=4)
	{
		// Extract alpha channel
		if(!function_exists('gzuncompress'))
			$this->Error('Zlib not available, can\'t handle alpha channel: '.$file);
		$data = gzuncompress($data);
		$color = '';
		$alpha = '';
		if($ct==4)
		{
			// Gray image
			$len = 2*$w;
			for($i=0;$i<$h;$i++)
			{
				$pos = (1+$len)*$i;
				$color .= $data[$pos];
				$alpha .= $data[$pos];
				$line = substr($data,$pos+1,$len);
				$color .= preg_replace('/(.)./s','$1',$line);
				$alpha .= preg_replace('/.(.)/s','$1',$line);
			}
		}
		else
		{
			// RGB image
			$len = 4*$w;
			for($i=0;$i<$h;$i++)
			{
				$pos = (1+$len)*$i;
				$color .= $data[$pos];
				$alpha .= $data[$pos];
				$line = substr($data,$pos+1,$len);
				$color .= preg_replace('/(.{3})./s','$1',$line);
				$alpha .= preg_replace('/.{3}(.)/s','$1',$line);
			}
		}
		unset($data);
		$data = gzcompress($color);
		$info['smask'] = gzcompress($alpha);
		$this->WithAlpha = true;
		if($this->PDFVersion<'1.4')
			$this->PDFVersion = '1.4';
	}
	$info['data'] = $data;
	return $info;
}

protected function _readstream($f, $n)
{
	// Read n bytes from stream
	$res = '';
	while($n>0 && !feof($f))
	{
		$s = fread($f,$n);
		if($s===false)
			$this->Error('Error while reading stream');
		$n -= strlen($s);
		$res .= $s;
	}
	if($n>0)
		$this->Error('Unexpected end of stream');
	return $res;
}

protected function _readint($f)
{
	// Read a 4-byte integer from stream
	$a = unpack('Ni',$this->_readstream($f,4));
	return $a['i'];
}

protected function _parsegif($file)
{
	// Extract info from a GIF file (via PNG conversion)
	if(!function_exists('imagepng'))
		$this->Error('GD extension is required for GIF support');
	if(!function_exists('imagecreatefromgif'))
		$this->Error('GD has no GIF read support');
	$im = imagecreatefromgif($file);
	if(!$im)
		$this->Error('Missing or incorrect image file: '.$file);
	imageinterlace($im,0);
	ob_start();
	imagepng($im);
	$data = ob_get_clean();
	imagedestroy($im);
	$f = fopen('php://temp','rb+');
	if(!$f)
		$this->Error('Unable to create memory stream');
	fwrite($f,$data);
	rewind($f);
	$info = $this->_parsepngstream($f,$file);
	fclose($f);
	return $info;
}

// Mode de rendu du texte (opérateur PDF « Tr ») : 0 = plein (défaut),
// 1 = contour seul (lettres creuses). Sert au tampon « AUTHENTIQUE » posé
// PAR-DESSUS un document dense sans en masquer le contenu (03/10/2026).
public function ModeTexte(int $mode): void
{
	$this->_out(max(0, min(7, $mode)) . ' Tr');
}

protected function _out($s)
{
	// Add a line to the current page
	if($this->state==2)
		$this->pages[$this->page] .= $s."\n";
	elseif($this->state==0)
		$this->Error('No page has been added yet');
	elseif($this->state==1)
		$this->Error('Invalid call');
	elseif($this->state==3)
		$this->Error('The document is closed');
}

protected function _put($s)
{
	// Add a line to the document
	$this->buffer .= $s."\n";
}

protected function _getoffset()
{
	return strlen($this->buffer);
}

protected function _newobj($n=null)
{
	// Begin a new object
	if($n===null)
		$n = ++$this->n;
	$this->offsets[$n] = $this->_getoffset();
	$this->_put($n.' 0 obj');
}

protected function _putstream($data)
{
	$this->_put('stream');
	$this->_put($data);
	$this->_put('endstream');
}

protected function _putstreamobject($data)
{
	if($this->compress)
	{
		$entries = '/Filter /FlateDecode ';
		$data = gzcompress($data);
	}
	else
		$entries = '';
	$entries .= '/Length '.strlen($data);
	$this->_newobj();
	$this->_put('<<'.$entries.'>>');
	$this->_putstream($data);
	$this->_put('endobj');
}

protected function _putlinks($n)
{
	foreach($this->PageLinks[$n] as $pl)
	{
		$this->_newobj();
		$rect = sprintf('%.2F %.2F %.2F %.2F',$pl[0],$pl[1],$pl[0]+$pl[2],$pl[1]-$pl[3]);
		$s = '<</Type /Annot /Subtype /Link /Rect ['.$rect.'] /Border [0 0 0] ';
		if(is_string($pl[4]))
			$s .= '/A <</S /URI /URI '.$this->_textstring($pl[4]).'>>>>';
		else
		{
			$l = $this->links[$pl[4]];
			if(isset($this->PageInfo[$l[0]]['size']))
				$h = $this->PageInfo[$l[0]]['size'][1];
			else
				$h = ($this->DefOrientation=='P') ? $this->DefPageSize[1]*$this->k : $this->DefPageSize[0]*$this->k;
			$s .= sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]>>',$this->PageInfo[$l[0]]['n'],$h-$l[1]*$this->k);
		}
		$this->_put($s);
		$this->_put('endobj');
	}
}

protected function _putpage($n)
{
	$this->_newobj();
	$this->_put('<</Type /Page');
	$this->_put('/Parent 1 0 R');
	if(isset($this->PageInfo[$n]['size']))
		$this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]',$this->PageInfo[$n]['size'][0],$this->PageInfo[$n]['size'][1]));
	if(isset($this->PageInfo[$n]['rotation']))
		$this->_put('/Rotate '.$this->PageInfo[$n]['rotation']);
	$this->_put('/Resources 2 0 R');
	if(!empty($this->PageLinks[$n]))
	{
		$s = '/Annots [';
		foreach($this->PageLinks[$n] as $pl)
			$s .= $pl[5].' 0 R ';
		$s .= ']';
		$this->_put($s);
	}
	if($this->WithAlpha)
		$this->_put('/Group <</Type /Group /S /Transparency /CS /DeviceRGB>>');
	$this->_put('/Contents '.($this->n+1).' 0 R>>');
	$this->_put('endobj');
	// Page content
	if(!empty($this->AliasNbPages))
		$this->pages[$n] = str_replace($this->AliasNbPages,$this->page,$this->pages[$n]);
	$this->_putstreamobject($this->pages[$n]);
	// Link annotations
	$this->_putlinks($n);
}

protected function _putpages()
{
	$nb = $this->page;
	$n = $this->n;
	for($i=1;$i<=$nb;$i++)
	{
		$this->PageInfo[$i]['n'] = ++$n;
		$n++;
		foreach($this->PageLinks[$i] as &$pl)
			$pl[5] = ++$n;
		unset($pl);
	}
	for($i=1;$i<=$nb;$i++)
		$this->_putpage($i);
	// Pages root
	$this->_newobj(1);
	$this->_put('<</Type /Pages');
	$kids = '/Kids [';
	for($i=1;$i<=$nb;$i++)
		$kids .= $this->PageInfo[$i]['n'].' 0 R ';
	$kids .= ']';
	$this->_put($kids);
	$this->_put('/Count '.$nb);
	if($this->DefOrientation=='P')
	{
		$w = $this->DefPageSize[0];
		$h = $this->DefPageSize[1];
	}
	else
	{
		$w = $this->DefPageSize[1];
		$h = $this->DefPageSize[0];
	}
	$this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]',$w*$this->k,$h*$this->k));
	$this->_put('>>');
	$this->_put('endobj');
}

protected function _putfonts()
{
	foreach($this->FontFiles as $file=>$info)
	{
		// Font file embedding
		$this->_newobj();
		$this->FontFiles[$file]['n'] = $this->n;
		$font = file_get_contents($file);
		if(!$font)
			$this->Error('Font file not found: '.$file);
		$compressed = (substr($file,-2)=='.z');
		if(!$compressed && isset($info['length2']))
			$font = substr($font,6,$info['length1']).substr($font,6+$info['length1']+6,$info['length2']);
		$this->_put('<</Length '.strlen($font));
		if($compressed)
			$this->_put('/Filter /FlateDecode');
		$this->_put('/Length1 '.$info['length1']);
		if(isset($info['length2']))
			$this->_put('/Length2 '.$info['length2'].' /Length3 0');
		$this->_put('>>');
		$this->_putstream($font);
		$this->_put('endobj');
	}
	foreach($this->fonts as $k=>$font)
	{
		// Encoding
		if(isset($font['diff']))
		{
			if(!isset($this->encodings[$font['enc']]))
			{
				$this->_newobj();
				$this->_put('<</Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ['.$font['diff'].']>>');
				$this->_put('endobj');
				$this->encodings[$font['enc']] = $this->n;
			}
		}
		// ToUnicode CMap
		if(isset($font['uv']))
		{
			if(isset($font['enc']))
				$cmapkey = $font['enc'];
			else
				$cmapkey = $font['name'];
			if(!isset($this->cmaps[$cmapkey]))
			{
				$cmap = $this->_tounicodecmap($font['uv']);
				$this->_putstreamobject($cmap);
				$this->cmaps[$cmapkey] = $this->n;
			}
		}
		// Font object
		$this->fonts[$k]['n'] = $this->n+1;
		$type = $font['type'];
		$name = $font['name'];
		if($font['subsetted'])
			$name = 'AAAAAA+'.$name;
		if($type=='Core')
		{
			// Core font
			$this->_newobj();
			$this->_put('<</Type /Font');
			$this->_put('/BaseFont /'.$name);
			$this->_put('/Subtype /Type1');
			if($name!='Symbol' && $name!='ZapfDingbats')
				$this->_put('/Encoding /WinAnsiEncoding');
			if(isset($font['uv']))
				$this->_put('/ToUnicode '.$this->cmaps[$cmapkey].' 0 R');
			$this->_put('>>');
			$this->_put('endobj');
		}
		elseif($type=='Type1' || $type=='TrueType')
		{
			// Additional Type1 or TrueType/OpenType font
			$this->_newobj();
			$this->_put('<</Type /Font');
			$this->_put('/BaseFont /'.$name);
			$this->_put('/Subtype /'.$type);
			$this->_put('/FirstChar 32 /LastChar 255');
			$this->_put('/Widths '.($this->n+1).' 0 R');
			$this->_put('/FontDescriptor '.($this->n+2).' 0 R');
			if(isset($font['diff']))
				$this->_put('/Encoding '.$this->encodings[$font['enc']].' 0 R');
			else
				$this->_put('/Encoding /WinAnsiEncoding');
			if(isset($font['uv']))
				$this->_put('/ToUnicode '.$this->cmaps[$cmapkey].' 0 R');
			$this->_put('>>');
			$this->_put('endobj');
			// Widths
			$this->_newobj();
			$cw = $font['cw'];
			$s = '[';
			for($i=32;$i<=255;$i++)
				$s .= $cw[chr($i)].' ';
			$this->_put($s.']');
			$this->_put('endobj');
			// Descriptor
			$this->_newobj();
			$s = '<</Type /FontDescriptor /FontName /'.$name;
			foreach($font['desc'] as $k=>$v)
				$s .= ' /'.$k.' '.$v;
			if(!empty($font['file']))
				$s .= ' /FontFile'.($type=='Type1' ? '' : '2').' '.$this->FontFiles[$font['file']]['n'].' 0 R';
			$this->_put($s.'>>');
			$this->_put('endobj');
		}
		else
		{
			// Allow for additional types
			$mtd = '_put'.strtolower($type);
			if(!method_exists($this,$mtd))
				$this->Error('Unsupported font type: '.$type);
			$this->$mtd($font);
		}
	}
}

protected function _tounicodecmap($uv)
{
	$ranges = '';
	$nbr = 0;
	$chars = '';
	$nbc = 0;
	foreach($uv as $c=>$v)
	{
		if(is_array($v))
		{
			$ranges .= sprintf("<%02X> <%02X> <%04X>\n",$c,$c+$v[1]-1,$v[0]);
			$nbr++;
		}
		else
		{
			$chars .= sprintf("<%02X> <%04X>\n",$c,$v);
			$nbc++;
		}
	}
	$s = "/CIDInit /ProcSet findresource begin\n";
	$s .= "12 dict begin\n";
	$s .= "begincmap\n";
	$s .= "/CIDSystemInfo\n";
	$s .= "<</Registry (Adobe)\n";
	$s .= "/Ordering (UCS)\n";
	$s .= "/Supplement 0\n";
	$s .= ">> def\n";
	$s .= "/CMapName /Adobe-Identity-UCS def\n";
	$s .= "/CMapType 2 def\n";
	$s .= "1 begincodespacerange\n";
	$s .= "<00> <FF>\n";
	$s .= "endcodespacerange\n";
	if($nbr>0)
	{
		$s .= "$nbr beginbfrange\n";
		$s .= $ranges;
		$s .= "endbfrange\n";
	}
	if($nbc>0)
	{
		$s .= "$nbc beginbfchar\n";
		$s .= $chars;
		$s .= "endbfchar\n";
	}
	$s .= "endcmap\n";
	$s .= "CMapName currentdict /CMap defineresource pop\n";
	$s .= "end\n";
	$s .= "end";
	return $s;
}

protected function _putimages()
{
	foreach(array_keys($this->images) as $file)
	{
		$this->_putimage($this->images[$file]);
		unset($this->images[$file]['data']);
		unset($this->images[$file]['smask']);
	}
}

protected function _putimage(&$info)
{
	$this->_newobj();
	$info['n'] = $this->n;
	$this->_put('<</Type /XObject');
	$this->_put('/Subtype /Image');
	$this->_put('/Width '.$info['w']);
	$this->_put('/Height '.$info['h']);
	if($info['cs']=='Indexed')
		$this->_put('/ColorSpace [/Indexed /DeviceRGB '.(strlen($info['pal'])/3-1).' '.($this->n+1).' 0 R]');
	else
	{
		$this->_put('/ColorSpace /'.$info['cs']);
		if($info['cs']=='DeviceCMYK')
			$this->_put('/Decode [1 0 1 0 1 0 1 0]');
	}
	$this->_put('/BitsPerComponent '.$info['bpc']);
	if(isset($info['f']))
		$this->_put('/Filter /'.$info['f']);
	if(isset($info['dp']))
		$this->_put('/DecodeParms <<'.$info['dp'].'>>');
	if(isset($info['trns']) && is_array($info['trns']))
	{
		$trns = '';
		for($i=0;$i<count($info['trns']);$i++)
			$trns .= $info['trns'][$i].' '.$info['trns'][$i].' ';
		$this->_put('/Mask ['.$trns.']');
	}
	if(isset($info['smask']))
		$this->_put('/SMask '.($this->n+1).' 0 R');
	$this->_put('/Length '.strlen($info['data']).'>>');
	$this->_putstream($info['data']);
	$this->_put('endobj');
	// Soft mask
	if(isset($info['smask']))
	{
		$dp = '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns '.$info['w'];
		$smask = array('w'=>$info['w'], 'h'=>$info['h'], 'cs'=>'DeviceGray', 'bpc'=>8, 'f'=>$info['f'], 'dp'=>$dp, 'data'=>$info['smask']);
		$this->_putimage($smask);
	}
	// Palette
	if($info['cs']=='Indexed')
		$this->_putstreamobject($info['pal']);
}

protected function _putxobjectdict()
{
	foreach($this->images as $image)
		$this->_put('/I'.$image['i'].' '.$image['n'].' 0 R');
}

protected function _putresourcedict()
{
    // Code original
    $this->_put('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
    $this->_put('/Font <<');
    foreach($this->fonts as $font)
        $this->_put('/F'.$font['i'].' '.$font['n'].' 0 R');
    $this->_put('>>');
    $this->_put('/XObject <<');
    $this->_putxobjectdict();
    $this->_put('>>');

    // Ajout transparence
    /*if(!empty($this->extgstates)) {
        $this->_out('/ExtGState <<');
        foreach($this->extgstates as $k=>$parms)
            $this->_out('/GS'.$k.' '.$this->extgstates[$k]['n'].' 0 R');
        $this->_out('>>');
    }*/
}
protected function _putresources()
{
    // Code original
    $this->_putfonts();
    $this->_putimages();
    // Resource dictionary
    $this->_newobj(2);
    $this->_put('<<');
    $this->_putresourcedict();
    $this->_put('>>');
    $this->_put('endobj');

    // Ajout transparence
    /*if(!empty($this->extgstates)) {
        foreach($this->extgstates as $k=>$parms) {
            $this->_putextgstate($parms);
        }
    }*/
}
	
	

protected function _putinfo()
{
	$date = @date('YmdHisO',$this->CreationDate);
	$this->metadata['CreationDate'] = 'D:'.substr($date,0,-2)."'".substr($date,-2)."'";
	foreach($this->metadata as $key=>$value)
		$this->_put('/'.$key.' '.$this->_textstring($value));
}

protected function _putcatalog()
{
	$n = $this->PageInfo[1]['n'];
	$this->_put('/Type /Catalog');
	$this->_put('/Pages 1 0 R');
	if($this->ZoomMode=='fullpage')
		$this->_put('/OpenAction ['.$n.' 0 R /Fit]');
	elseif($this->ZoomMode=='fullwidth')
		$this->_put('/OpenAction ['.$n.' 0 R /FitH null]');
	elseif($this->ZoomMode=='real')
		$this->_put('/OpenAction ['.$n.' 0 R /XYZ null null 1]');
	elseif(!is_string($this->ZoomMode))
		$this->_put('/OpenAction ['.$n.' 0 R /XYZ null null '.sprintf('%.2F',$this->ZoomMode/100).']');
	if($this->LayoutMode=='single')
		$this->_put('/PageLayout /SinglePage');
	elseif($this->LayoutMode=='continuous')
		$this->_put('/PageLayout /OneColumn');
	elseif($this->LayoutMode=='two')
		$this->_put('/PageLayout /TwoColumnLeft');
}

protected function _putheader()
{
	$this->_put('%PDF-'.$this->PDFVersion);
}

protected function _puttrailer()
{
	$this->_put('/Size '.($this->n+1));
	$this->_put('/Root '.$this->n.' 0 R');
	$this->_put('/Info '.($this->n-1).' 0 R');
}

protected function _enddoc()
{
    // Ajout transparence
    if(!empty($this->extgstates) && $this->PDFVersion<'1.4') {
        $this->PDFVersion='1.4';
    }

    // Code original
    $this->CreationDate = time();
    $this->_putheader();
    $this->_putpages();
    $this->_putresources();
    // Info
    $this->_newobj();
    $this->_put('<<');
    $this->_putinfo();
    $this->_put('>>');
    $this->_put('endobj');
    // Catalog
    $this->_newobj();
    $this->_put('<<');
    $this->_putcatalog();
    $this->_put('>>');
    $this->_put('endobj');
    // Cross-ref
    $offset = $this->_getoffset();
    $this->_put('xref');
    $this->_put('0 '.($this->n+1));
    $this->_put('0000000000 65535 f ');
    for($i=1;$i<=$this->n;$i++)
        $this->_put(sprintf('%010d 00000 n ',$this->offsets[$i]));
    // Trailer
    $this->_put('trailer');
    $this->_put('<<');
    $this->_puttrailer();
    $this->_put('>>');
    $this->_put('startxref');
    $this->_put($offset);
    $this->_put('%%EOF');
    $this->state = 3;
}
	
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////	
//////////////  FONCTIONS AJOUTEES DANS LA CLASSE FPDF ////////////////////	
///////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////	
///////////////////////////////////////////////////////////////////////////
	
function CheckPageBreak_for_list($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY){
	//Si la hauteur h provoque un débordement, saut de page manuel
	if($this->GetY()+$h>$this->PageBreakTrigger){
		//On imprime les colonnes de la page actuelle
		$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
		//On ajoute une page
		$this->AddPage();
		//On réimprime l'entête du tableau
		$this->ln(-40);
		$this->SetX($setX);
		$this->printTableHeader_haut($header,$w,$al,1,4);
	$this->ln();
		$this->SetX($setX);
		
		//On renvoi la position courante sur la nouvelle page
		return ($this->GetY());
	}
	//On a pas effectué de saut on revoie 0
	return 0;
}



function CheckPageBreak_for_list_without_header($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY){
	//Si la hauteur h provoque un débordement, saut de page manuel
	if($this->GetY()+$h>$this->PageBreakTrigger){
		//On imprime les colonnes de la page actuelle
		$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
		//On ajoute une page
		$this->AddPage();
		//On réimprime l'entête du tableau
		$this->ln(-40);
		$this->SetX($setX);
		//$this->printTableHeader_haut($header,$w,$al,1,4);
	//$this->ln();
		//$this->SetX($setX);
		
		//On renvoi la position courante sur la nouvelle page
		return ($this->GetY());
	}
	//On a pas effectué de saut on revoie 0
	return 0;
}	
//Tracé des colonnes
function PrintCols($w,$posStartX,$posStartY,$posAfterY){
	$this->Line($posStartX,$posStartY,$posStartX,$posAfterY);
	$colX=$posStartX;
	//On trace la ligne pour chaque colonne
	foreach($w as $row){
		$colX+=$row;
		$this->Line($colX,$posStartY,$colX,$posAfterY);
	}
}	
// Tableau coloré
function FancyTable($header, $data)
{
    // Couleurs, épaisseur du trait et police grasse
    $this->SetFillColor(255,0,0);
    $this->SetTextColor(255);
    $this->SetDrawColor(128,0,0);
    $this->SetLineWidth(.3);
    $this->SetFont('','B');
    // En-tête
    $w = array(40, 35, 45, 40);
    for($i=0;$i<count($header);$i++)
        $this->Cell($w[$i],7,$header[$i],1,0,'C',true);
    $this->Ln();
    // Restauration des couleurs et de la police
    $this->SetFillColor(224,235,255);
    $this->SetTextColor(0);
    $this->SetFont('');
    // Données
    $fill = false;
    foreach($data as $row)
    {
        $this->Cell($w[0],6,$row[0],'LR',0,'L',$fill);
        $this->Cell($w[1],6,$row[1],'LR',0,'L',$fill);
        $this->Cell($w[2],6,number_format($row[2],0,',',' '),'LR',0,'R',$fill);
        $this->Cell($w[3],6,number_format($row[3],0,',',' '),'LR',0,'R',$fill);
        $this->Ln();
        $fill = !$fill;
    }
    // Trait de terminaison
    $this->Cell(array_sum($w),0,'','T');
}
/////// tableau avec une grande hauteur des lignes
function table_haut($header,$w,$al,$datas){
	//Impression de l'entête tableau
	$this->SetLineWidth(.3);
	//$this->printTableHeader($header,$w);
 
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
			$this->MultiCell($w[$i],30,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}


// tableau avec ecartement de lignes tres petit
function table_pour_entete($header,$w,$al,$datas,$haut){
	//Impression de l'entête tableau
	//$this->SetLineWidth(.3);
	//$this->printTableHeader($header,$w);
 
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		///$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	//$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}






// tableau avec ecartement de lignes tres petit
function table_etab($header,$w,$al,$datas){
	//Impression de l'entête tableau
	//$this->SetLineWidth(.3);
	//$this->printTableHeader($header,$w);
 
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
			$this->MultiCell($w[$i],3.4,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		///$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	//$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}




// tableau avec ecartement de lignes tres petit
function table_etab_with_haut($header,$w,$al,$datas,$haut){
	//Impression de l'entête tableau
	//$this->SetLineWidth(.3);
	//$this->printTableHeader($header,$w);
 
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		///$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	//$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}








// tableau avec ecartement de lignes tres petit
function table_petit($header,$w,$al,$datas){
	//Impression de l'entête tableau
	$this->SetLineWidth(.3);
	//$this->printTableHeader($header,$w);
 
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
			$this->MultiCell($w[$i],4,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}


/* Dessine un tableau ayant pour parametre :
- $header= entete array('No','Nom','Date',..) 
- $w= dimension de cellule , un table array(10,10,10,..)
- $al= alignement des elts de la cellule array('C','L','R',...)
- $haut = hauteur des lignes h=4
*/
function table_gd($header,$w,$al,$datas,$haut){
	//Impression de l'entête tableau
	$this->SetLineWidth(.3);
	//$this->printTableHeader($header,$w);
 
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}
// tableau avec ecartement de lignes tres gd
	//table_gd_with_fill($header,$w,$al,$datas,$haut,$fill)
function table_gd_with_fill($header,$w,$al,$datas,$haut,$fill){
	//Impression de l'entête tableau
	$this->SetLineWidth(.3);
	//$this->printTableHeader($header,$w);
 
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;$j=2;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	//$this->SetFillColor(106,181,255);	
    //$this->SetTextColor(255);
    //$this->SetDrawColor(128,0,0);
		//$fill = false;
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
			//if($j%2==0)
				$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],$fill);
			//else
				//$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],true);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);

			$j++;
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		$fill = !$fill;
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}

  // tableau avec des ecarts des lignes et police (font) personnalisable
function table_gd_for_list_font($header,$w,$al,$setX,$datas,$haut,$font){
	//Impression de l'entête tableau
 
	$this->SetLineWidth(.3);
	$this->printTableHeader_haut_SetFont($header,$w,$al,1,4,$font);
 $this->ln();
   $font;
 $this->SetX($setX);
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
  
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
      
		}

		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}


// tableau avec ecartement de lignes tres gd
function table_sans_header_Avec_Font($header,$w,$al,$setX,$datas,$haut,$font){
	//Impression de l'entête tableau
	$this->SetLineWidth(.3);
	
	//$this->printTableHeader_haut($header,$w,$al,1,4);
	 $this->ln();
	 $this->SetX($setX);
	 $font;
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}


	

function printTableRowsComplexPlus0($donnees, $jours, $periodes, $wColonnesFixes, $labelsFixes, $wColonnesFinales, $labelsFinales, $hauteur, $fontFamily, $fontStyle, $fontSize, $wPeriode, $setX)
{
    // Initialisation : dessiner l'en-tête sur la première page
    $this->SetFont($fontFamily, 'B', $fontSize);
    $this->SetX($setX);
    $this->printTableHeaderComplexPlus(
        $jours, $periodes,
        $wColonnesFixes, $labelsFixes,
        $wColonnesFinales, $labelsFinales,
        $hauteur, $fontFamily, 'B', $fontSize,
        $wPeriode, $setX
    );

    // Style normal pour les lignes
    $this->SetFont($fontFamily, '', $fontSize);

    foreach ($donnees as $ligne) {
        // Vérifie si la ligne suivante dépasse la hauteur disponible
        if ($this->GetY() + $hauteur > ($this->h - $this->bMargin)) {
            $this->AddPage();
            $this->SetFont($fontFamily, 'B', $fontSize);
            $this->SetX($setX);
            $this->printTableHeaderComplexPlus(
                $jours, $periodes,
                $wColonnesFixes, $labelsFixes,
                $wColonnesFinales, $labelsFinales,
                $hauteur, $fontFamily, 'B', $fontSize,
                $wPeriode, $setX
            );
            $this->SetFont($fontFamily, '', $fontSize);
        }

        $this->SetX($setX);

        // Colonnes fixes
        foreach ($wColonnesFixes as $i => $w) {
            $this->Cell($w, $hauteur, strtoupper($ligne['fixes'][$i]), 1, 0, 'C');
        }

        // Cellules des périodes
        foreach ($jours as $jour) {
            foreach ($periodes as $periode) {
                $valeur = isset($ligne['periodes'][$jour][$periode]) ? $ligne['periodes'][$jour][$periode] : '';
                $this->Cell($wPeriode, $hauteur, strtoupper($valeur), 1, 0, 'C');
            }
        }

        // Colonnes finales
        foreach ($wColonnesFinales as $i => $w) {
            $this->Cell($w, $hauteur, strtoupper($ligne['finales'][$i]), 1, 0, 'C');
        }

        $this->Ln();
    }
}


	
	
function printTableHeaderComplexPlus_Abz($jours, $periodes, $wColonnesFixes, $labelsFixes, $wColonnesFinales, $labelsFinales, $hauteur, $fontFamily, $fontStyle, $fontSize, $wPeriode, $setX)
{
    $periodesLimite = ['T1', 'T2', 'T3', 'T4', 'T5'];

    $this->SetFont($fontFamily, $fontStyle, $fontSize);
    $this->SetFillColor(230, 230, 230);

    // 🔹 Colonnes fixes fusionnées verticalement
    foreach ($labelsFixes as $i => $label) {
        $this->Cell($wColonnesFixes[$i], $hauteur * 2, strtoupper($label), 1, 0, 'C', true);
    }

    // 🔹 Entête des jours avec largeur dynamique selon les périodes
    foreach ($jours as $jour) {
        $jourMaj = strtoupper($jour);
        $periodesActives = ($jourMaj === 'MERCREDI') ? $periodesLimite : $periodes;
        $this->Cell($wPeriode * count($periodesActives), $hauteur, strtoupper($jour), 1, 0, 'C', true);
    }

    // 🔹 Colonnes finales fusionnées verticalement
    foreach ($labelsFinales as $i => $label) {
        $this->Cell($wColonnesFinales[$i], $hauteur * 2, strtoupper($label), 1, 0, 'C', true);
    }

    $this->Ln($hauteur);
    $this->SetX($setX);

    // 🔹 Ligne des périodes
    $totalFixedWidth = array_sum($wColonnesFixes);
    $this->Cell($totalFixedWidth, $hauteur, '', 0, 0);

    foreach ($jours as $jour) {
        $jourMaj = strtoupper($jour);
        $periodesActives = ($jourMaj === 'MERCREDI') ? $periodesLimite : $periodes;

        foreach ($periodesActives as $periode) {
            $this->Cell($wPeriode, $hauteur, strtoupper($periode), 1, 0, 'C');
        }
    }

    $totalFinalWidth = array_sum($wColonnesFinales);
    $this->Cell($totalFinalWidth, $hauteur, '', 0, 0);

    $this->Ln();
}	
	
	
	
function printTableRowsComplexPlus_Abz($donnees, $jours, $periodes, $wColonnesFixes, $labelsFixes, $wColonnesFinales, $labelsFinales, $hauteur, $fontFamily, $fontStyle, $fontSize, $wPeriode, $setX)
{
    $periodesLimite = ['T1', 'T2', 'T3', 'T4', 'T5'];

    $this->SetFont($fontFamily, 'B', $fontSize);
    $this->SetX($setX);
    $this->printTableHeaderComplexPlus_Abz(
        $jours, $periodes,
        $wColonnesFixes, $labelsFixes,
        $wColonnesFinales, $labelsFinales,
        $hauteur, $fontFamily, 'B', $fontSize,
        $wPeriode, $setX
    );

    $this->SetFont($fontFamily, '', $fontSize);

    foreach ($donnees as $ligne) {
        $nom = strtoupper($ligne['fixes'][1]);
        $nomWidth = $wColonnesFixes[1];
        $nomLines = $this->NbLines($nomWidth, $nom);

        $lineHeight = ($nomLines > 1) ? round($hauteur * 0.6, 2) : $hauteur;
        $rowHeight = $lineHeight * $nomLines;

        if ($this->GetY() + $rowHeight > ($this->h - $this->bMargin)) {
            $this->AddPage();
            $this->SetFont($fontFamily, 'B', $fontSize);
            $this->SetX($setX);
            $this->printTableHeaderComplexPlus_Abz(
                $jours, $periodes,
                $wColonnesFixes, $labelsFixes,
                $wColonnesFinales, $labelsFinales,
                $hauteur, $fontFamily, 'B', $fontSize,
                $wPeriode, $setX
            );
            $this->SetFont($fontFamily, '', $fontSize);
        }

        $xStart = $setX;
        $yStart = $this->GetY();

        $this->SetXY($xStart, $yStart);
        $this->Cell($wColonnesFixes[0], $rowHeight, strtoupper($ligne['fixes'][0]), 1, 0, 'C');

        $xNom = $xStart + $wColonnesFixes[0];
        $this->SetXY($xNom, $yStart);
        $this->MultiCell($wColonnesFixes[1], $lineHeight, $nom, 1, 'L');

        $xPeriodes = $xNom + $wColonnesFixes[1];
        $this->SetXY($xPeriodes, $yStart);

        foreach ($jours as $jour) {
            $jourMaj = strtoupper($jour);
            $periodesActives = ($jourMaj === 'MERCREDI') ? $periodesLimite : $periodes;

            foreach ($periodesActives as $periode) {
                $valeur = isset($ligne['periodes'][$jour][$periode]) ? strtoupper($ligne['periodes'][$jour][$periode]) : '';
                $this->Cell($wPeriode, $rowHeight, $valeur, 1, 0, 'C');
            }
        }

        foreach ($wColonnesFinales as $i => $w) {
            $this->Cell($w, $rowHeight, strtoupper($ligne['finales'][$i]), 1, 0, 'C');
        }

        $this->Ln($rowHeight);
    }
}	
	
	


	
	
function printTableRowsComplexPlus($donnees, $jours, $periodes, $wColonnesFixes, $labelsFixes, $wColonnesFinales, $labelsFinales, $hauteur, $fontFamily, $fontStyle, $fontSize, $wPeriode, $setX)
{
    $this->SetFont($fontFamily, 'B', $fontSize);
    $this->SetX($setX);
    $this->printTableHeaderComplexPlus(
        $jours, $periodes,
        $wColonnesFixes, $labelsFixes,
        $wColonnesFinales, $labelsFinales,
        $hauteur, $fontFamily, 'B', $fontSize,
        $wPeriode, $setX
    );

    $this->SetFont($fontFamily, '', $fontSize);

    foreach ($donnees as $ligne) {
        $nom = strtoupper($ligne['fixes'][1]);
        $nomWidth = $wColonnesFixes[1];
        $nomLines = $this->NbLines($nomWidth, $nom);

        // 🔹 Interligne dynamique
        $lineHeight = ($nomLines > 1) ? round($hauteur * 0.6, 2) : $hauteur;
        $rowHeight = $lineHeight * $nomLines;

        // 🔁 Saut de page si nécessaire
        if ($this->GetY() + $rowHeight > ($this->h - $this->bMargin)) {
            $this->AddPage();
            $this->SetFont($fontFamily, 'B', $fontSize);
            $this->SetX($setX);
            $this->printTableHeaderComplexPlus(
                $jours, $periodes,
                $wColonnesFixes, $labelsFixes,
                $wColonnesFinales, $labelsFinales,
                $hauteur, $fontFamily, 'B', $fontSize,
                $wPeriode, $setX
            );
            $this->SetFont($fontFamily, '', $fontSize);
        }

        $xStart = $setX;
        $yStart = $this->GetY();

        // 🔹 Colonne N° (centrée)
        $this->SetXY($xStart, $yStart);
        $this->Cell($wColonnesFixes[0], $rowHeight, strtoupper($ligne['fixes'][0]), 1, 0, 'C');

        // 🔹 Colonne NOM (MultiCell avec interligne dynamique)
        $xNom = $xStart + $wColonnesFixes[0];
        $this->SetXY($xNom, $yStart);
        $this->MultiCell($wColonnesFixes[1], $lineHeight, $nom, 1, 'L');

        // 🔹 Repositionnement pour les périodes
        $xPeriodes = $xNom + $wColonnesFixes[1];
        $this->SetXY($xPeriodes, $yStart);

        // 🔹 Cellules des périodes
        foreach ($jours as $jour) {
            foreach ($periodes as $periode) {
                $valeur = isset($ligne['periodes'][$jour][$periode]) ? strtoupper($ligne['periodes'][$jour][$periode]) : '';
                $this->Cell($wPeriode, $rowHeight, $valeur, 1, 0, 'C');
            }
        }

        // 🔹 Colonnes finales
        foreach ($wColonnesFinales as $i => $w) {
            $this->Cell($w, $rowHeight, strtoupper($ligne['finales'][$i]), 1, 0, 'C');
        }

        $this->Ln($rowHeight);
    }
}
	
	
	
	
	
	
	
function printTableRowsComplexPlus1($donnees, $jours, $periodes, $wColonnesFixes, $labelsFixes, $wColonnesFinales, $labelsFinales, $hauteur, $fontFamily, $fontStyle, $fontSize, $wPeriode, $setX)
{
    
    $nbLignesParPage = floor(($this->GetPageHeight() - $this->GetY() - 1) / $hauteur); // marge de 10 en bas
    $ligneCompteur = 0;

    foreach ($donnees as $ligne) {
        // 🔁 Saut de page si nécessaire
        if ($ligneCompteur % $nbLignesParPage === 0) {
            if ($ligneCompteur > 0) {
                $this->AddPage();
            }
            $this->SetX($setX);
            $this->printTableHeaderComplexPlus(
                $jours, $periodes,
                $wColonnesFixes, $labelsFixes,
                $wColonnesFinales, $labelsFinales,
                $hauteur, $fontFamily, "B", $fontSize,
                $wPeriode, $setX
            );
        }

		$this->SetFont($fontFamily, $fontStyle, $fontSize);
        $this->SetX($setX);

        // 🔹 Colonnes fixes
        foreach ($wColonnesFixes as $i => $w) {
            $this->Cell($w, $hauteur, strtoupper($ligne['fixes'][$i]), 1, 0, 'L');
        }

        // 🔹 Cellules des périodes pour chaque jour
        foreach ($jours as $jour) {
            foreach ($periodes as $periode) {
                $valeur = isset($ligne['periodes'][$jour][$periode]) ? $ligne['periodes'][$jour][$periode] : '';
                $this->Cell($wPeriode, $hauteur, strtoupper($valeur), 1, 0, 'C');
            }
        }

        // 🔹 Colonnes finales
        foreach ($wColonnesFinales as $i => $w) {
            $this->Cell($w, $hauteur, strtoupper($ligne['finales'][$i]), 1, 0, 'C');
        }

        $this->Ln();
        $ligneCompteur++;
    }
}	
	
	
	
	
	
 // tableau avec des ecarts des lignes et police (font) personnalisable
function table_gd_for_list_font_Array1($header,$w,$al,$setX,$datas,$haut,$fontFamily, $fontStyle, $fontSize){
	//Impression de l'entête tableau
 
	//$this->SetLineWidth(.3);
	$this->printTableHeader_haut_SetFont_Array($header,$w,$al,1,($haut+1),$fontFamily, $fontStyle, $fontSize);
 	$this->ln();
   $this->SetX($setX);
   $this->SetFont($fontFamily, $fontStyle, $fontSize);
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
  
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak_for_list($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
      
		}

		$this->SetFont($fontFamily, $fontStyle, $fontSize);
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}


// tableau avec des ecarts des lignes et police (font) personnalisable
function table_gd_for_list_font_Array($header,$w,$al,$setX,$datas,$haut,$fontFamily, $fontStyle, $fontSize){
	//Impression de l'entête tableau
 
	//$this->SetLineWidth(.3);
	$this->printTableHeader_haut_SetFont_Array($header,$w,$al,1,($haut+1),$fontFamily, $fontStyle, $fontSize);
 	$this->ln();
   $this->SetX($setX);
   $this->SetFont($fontFamily, $fontStyle, $fontSize);
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
  
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak_for_list($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
      
		}

		$this->SetFont($fontFamily, $fontStyle, $fontSize);
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}


























// tableau avec ecartement de lignes tres gd
function table_gd_for_list_fill_repeat_header($header,$w,$al,$setX,$datas,$haut,$fill){
	//Impression de l'entête tableau
	$this->SetLineWidth(.3);
	$this->printTableHeader_haut($header,$w,$al,1,5);
 	$this->ln();
 	$this->SetX($setX);
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak_for_list($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],$fill);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		$fill = !$fill;
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}






// tableau avec ecartement de lignes tres gd
function table_gd_for_list($header,$w,$al,$setX,$datas,$haut){
	//Impression de l'entête tableau
	//$this->SetLineWidth(.3);
	//$this->printTableHeader_haut($header,$w,$al,1,4);
 $this->ln();
 $this->SetX($setX);
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak_for_list($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}




// tableau avec ecartement de lignes tres gd
function table_gd_for_list_special($header,$w,$al,$setX,$datas,$haut){
	//Impression de l'entête tableau
	//$this->SetLineWidth(.3);
	//$this->printTableHeader_haut($header,$w,$al,1,4);
 $this->ln();
 $this->SetX($setX);
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak_for_list($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}




// tableau avec ecartement de lignes tres gd
function table_gd_for_list_fill($header,$w,$al,$setX,$datas,$haut,$fill){
	//Impression de l'entête tableau
	//$this->SetLineWidth(.3);
	//$this->printTableHeader_haut($header,$w,$al,1,4);
 //$this->ln();
 $this->SetX($setX);
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak_for_list_without_header($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),1,$al[$i],$fill);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		$fill = !$fill;
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}














// tableau avec ecartement de lignes tres gd
function table_sans_header($header,$w,$al,$setX,$datas,$haut){
	//Impression de l'entête tableau
	$this->SetLineWidth(.3);
	
	//$this->printTableHeader_haut($header,$w,$al,1,4);
 $this->ln();
 $this->SetX($setX);
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak_for_list($h,$w,$header,$al,$setX,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
		/////yahya
		
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),'',$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		//Tracé de la ligne du dessous
		$this->Line($posStartX,$posAfterY,$posBeforeX,$posAfterY);
		$this->setXY($posStartX,$posAfterY);
		
		
	}
 
	//Tracé des colonnes
	$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
	//$b++;
	//}
}




// tableau avec ecartement de lignes tres gd
function table_sans_bordure($header,$w,$al,$datas,$haut){
	//Impression de l'entête tableau
	//$this->SetLineWidth(.3);
	//$this->printTableHeader($header,$w);
 
	$posStartX=$this->getX();	
	$posBeforeX=$posStartX;
 
	$posBeforeY=$this->getY();
	$posAfterY=$posBeforeY;
	$posStartY=$posBeforeY;
 	$b=0;
	//On parcours le tableau des données
	//for($b=0; $b< count($datas); $b++)
	//{
	foreach($datas as $row)
	{
		$posBeforeX=$posStartX;
		$posBeforeY=$posAfterY;
 
		//On vérifie qu'il n'y a pas débordement de page.
		$nb=0;
		for($i=0;$i<count($header);$i++)
		{
			$nb=max($nb,$this->NbLines($w[$i],$row[$i]));
		}
		$h=6*$nb;
 
		//Effectue un saut de page si il y a débordement
		$resultat = $this->CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY);
		if($resultat>0)
		{
			$posAfterY=$resultat;
			$posBeforeY=$resultat;
			$posStartY=$resultat;
		}
 
		//Impression de la ligne
		for($i=0;$i<count($header);$i++)
		{
			$this->MultiCell($w[$i],$haut,strip_tags($row[$i]),0,$al[$i],false);
			//On enregistre la plus grande hauteur de cellule
			if($posAfterY<$this->getY())
			{
				$posAfterY=$this->getY();
			}
			$posBeforeX+=$w[$i];
			$this->setXY($posBeforeX,$posBeforeY);
		}
		$this->setXY($posStartX,$posAfterY);	
	}
}

	
//Vérification du débordement de page
function CheckPageBreak($h,$w,$header,$posStartX,$posStartY,$posAfterY){
	//Si la hauteur h provoque un débordement, saut de page manuel
	if($this->GetY()+$h>$this->PageBreakTrigger){
		//On imprime les colonnes de la page actuelle
		$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
		//On ajoute une page
		$this->AddPage();
		//On réimprime l'entête du tableau
		//$this->printTableHeader($header,$w);
		//On renvoi la position courante sur la nouvelle page
		return ($this->GetY());
	}
	//On a pas effectué de saut on revoie 0
	return 0;
}
 
 //Vérification du débordement de page
function CheckPageBreak_avec_orientation($h,$w,$header,$posStartX,$posStartY,$posAfterY,$orientation){
	//Si la hauteur h provoque un débordement, saut de page manuel
	if($this->GetY()+$h>$this->PageBreakTrigger){
		//On imprime les colonnes de la page actuelle
		$this->PrintCols($w,$posStartX,$posStartY,$posAfterY);
		//On ajoute une page
		$this->AddPage($orientation);
		//On réimprime l'entête du tableau
		$this->SetXY(12,20);
		$this->printTableHeader($header,$w);
		$this->Ln(3);
		//On renvoi la position courante sur la nouvelle page
		return $this->GetY();
	}
	//On a pas effectué de saut on revoie 0
	return 0;
}
//Calcule le nombre de lignes qu'occupe un MultiCell de largeur w
function NbLines($w,$txt){
    $cw=&$this->CurrentFont['cw'];
    if($w==0)
        $w=$this->w-$this->rMargin-$this->x;
    $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
    $s=str_replace("\r",'',$txt);
    $nb=strlen($s);
    if($nb>0 and $s[$nb-1]=="\n")
        $nb--;
    $sep=-1;
    $i=0;
    $j=0;
    $l=0;
    $nl=1;
    while($i<$nb)
    {
        $c=$s[$i];
        if($c=="\n")
        {
            $i++;
            $sep=-1;
            $j=$i;
            $l=0;
            $nl++;
            continue;
        }
        if($c==' ')
            $sep=$i;
        $l+=$cw[$c];
        if($l>$wmax)
        {
            if($sep==-1)
            {
                if($i==$j)
                    $i++;
            }
            else
                $i=$sep+1;
            $sep=-1;
            $j=$i;
            $l=0;
            $nl++;
        }
        else
            $i++;
    }
    return $nl;
}
	

	

//Impression de l'entête du tableau
function printTableHeader_Font($header,$w,$font)
{
	//Couleurs, épaisseur du trait et police grasse
	//
	$this->SetMargins(5,60,5);
	//$this->SetFillColor(142,17,155);
	//$this->SetFont('Arial','B',9.5);
	$font;
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],3,$header[$i],0,0,'C',1);
}


//Impression de l'entête du tableau
function printTableHeader($header,$w)
{
	//Couleurs, épaisseur du trait et police grasse
	//
	$this->SetMargins(5,60,5);
	//$this->SetFillColor(142,17,155);
	$this->SetFont('Arial','B',9.5);
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],3,$header[$i],0,0,'C',1);
}
	

	
	
function printTableHeader_border_Font($header,$w,$border,$haut,$SetFont,$align)
{
	//Couleurs, épaisseur du trait et police grasse
	//
	$this->SetMargins(5,60,5);
	//$this->SetFillColor(142,17,155);
	//$this->SetFont('Arial','B',9.5);
	$SetFont;
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],$haut,$header[$i],$border,0,$align[$i],1);
}
function printTableHeader_border_Font_Fill($header,$w,$border,$haut,$SetFont,$align,$fill)
{
	//Couleurs, épaisseur du trait et police grasse
	//
	$this->SetMargins(5,60,5);
	//$this->SetFillColor(142,17,155);
	//$this->SetFont('Arial','B',9.5);
	$SetFont;
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],$haut,$header[$i],$border,0,$align[$i],$fill);
}
function printTableHeader_haut($header,$w,$align,$border,$hauteur)
{
	//Couleurs, épaisseur du trait et police grasse
	//
	$this->SetMargins(5,60,5);
	//$this->SetFillColor(142,17,155);
	$this->SetFont('Arial','B',9.5);
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],$hauteur,$header[$i],$border,0,$align[$i],1);
}

/*function My_CheckPageBreak($h)
    {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
    }*/
	
	
function My_CheckPageBreak($h)
    {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
            // Repositionner X et Y au d�but du tableau
            if ($this->tableStartX !== null) {
                $this->SetXY($this->tableStartX, $this->tMargin); 
                // $this->tMargin = marge haute d�finie par FPDF (par d�faut 10 mm)
            }
        }

    }

	
	
function FancyTable_DoubleLine($pdf, $data, $widths, $aligns = [], $rowHeight = 16, $startX = null, $borderStyle = 'simple', $fontsLine1 = [], $fontsLine2 = [], $colorsLine1 = [], $colorsLine2 = [], $lineSpacing = 3)
{
    if ($startX !== null) {
        $pdf->tableStartX = $startX;
    }

    foreach ($data as $row) {
        if ($startX !== null) {
            $pdf->SetX($startX);
        }

        $pdf->My_CheckPageBreak($rowHeight);

        for ($i = 0; $i < count($row); $i++) {
            $w = $widths[$i];
            $a = isset($aligns[$i]) ? $aligns[$i] : 'C';

            $x = $pdf->GetX();
            $y = $pdf->GetY();

            // --- Bordures ---
            $pdf->SetLineWidth(0.2);
            $pdf->Rect($x, $y, $w, $rowHeight);

            // --- Texte sur 2 lignes ---
            if (is_array($row[$i]) && count($row[$i]) == 2) {
                list($line1, $line2) = $row[$i];

                if (!empty($line1) && !empty($line2)) {
                    // D�finir polices et couleurs
                    list($f1,$s1,$sz1) = $fontsLine1[$i];
                    list($f2,$s2,$sz2) = $fontsLine2[$i];

                    // Hauteur de chaque ligne calcul�e � partir des tailles de police
                    $lineHeight1 = $sz1 * 0.35;
                    $lineHeight2 = $sz2 * 0.35;

                    // Hauteur totale du bloc texte
                    $blockHeight = $lineHeight1 + $lineSpacing + $lineHeight2;

                    // D�calage pour centrer verticalement
                    $offsetY = ($rowHeight - $blockHeight) / 2;

                    // Ligne 1
                    $pdf->SetFont($f1, $s1, $sz1);
                    // Ligne 1
					if (isset($colorsLine1[$i])) {
						if (is_callable($colorsLine1[$i])) {
							$col = $colorsLine1[$i]($line1); // appliquer la r�gle dynamique
							if (is_array($col) && count($col) === 3) {
								$pdf->SetTextColor($col[0], $col[1], $col[2]);
							}
						} elseif (is_array($colorsLine1[$i]) && count($colorsLine1[$i]) === 3) {
							$pdf->SetTextColor($colorsLine1[$i][0], $colorsLine1[$i][1], $colorsLine1[$i][2]);
						}
					} else {
						// couleur par d�faut si colonne non index�e
						$pdf->SetTextColor(0,0,0);
					}
                    $pdf->SetXY($x, $y + $offsetY);
                    $pdf->MultiCell($w, $lineHeight1, $line1, 0, $a);

                    // Ligne 2
                    $pdf->SetFont($f2, $s2, $sz2);
                    if (isset($colorsLine2[$i])) {
						if (is_callable($colorsLine2[$i])) {
							$col = $colorsLine2[$i]($line2);
							if (is_array($col) && count($col) === 3) {
								$pdf->SetTextColor($col[0], $col[1], $col[2]);
							}
						} elseif (is_array($colorsLine2[$i]) && count($colorsLine2[$i]) === 3) {
							$pdf->SetTextColor($colorsLine2[$i][0], $colorsLine2[$i][1], $colorsLine2[$i][2]);
						}
					} else {
						// couleur par d�faut si colonne non index�e
						$pdf->SetTextColor(0,0,0);
					}
                    $pdf->SetXY($x, $y + $offsetY + $lineHeight1+ $lineSpacing-1);
                    $pdf->MultiCell($w, $lineHeight2, $line2, 0, $a);

                } else {
                    // Cas : une seule ligne
                    $text = !empty($line1) ? $line1 : $line2;
                    list($f2,$s2,$sz2) = $fontsLine2[$i];
                    $pdf->SetFont($f2, $s2, $sz2);
                    if (isset($colorsLine1[$i])) {
						if (is_callable($colorsLine1[$i])) {
							$col = $colorsLine1[$i]($line1); // appliquer la r�gle dynamique
							if (is_array($col) && count($col) === 3) {
								$pdf->SetTextColor($col[0], $col[1], $col[2]);
							}
						} elseif (is_array($colorsLine1[$i]) && count($colorsLine1[$i]) === 3) {
							$pdf->SetTextColor($colorsLine1[$i][0], $colorsLine1[$i][1], $colorsLine1[$i][2]);
						}
					} else {
						// couleur par d�faut si colonne non index�e
						$pdf->SetTextColor(0,0,0);
					}

                    $lineHeight = $sz2 * 0.35;
                    $offsetY = ($rowHeight - $lineHeight) / 2;
                    $pdf->SetXY($x, $y + $offsetY);
                    $pdf->MultiCell($w, $lineHeight, $text, 0, $a);
                }

            } else {
                // fallback : texte simple
                list($f2,$s2,$sz2) = $fontsLine2[$i];
                $pdf->SetFont($f2, $s2, $sz2);
                if (isset($colorsLine1[$i])) {
						if (is_callable($colorsLine1[$i])) {
							$col = $colorsLine1[$i]($line1); // appliquer la r�gle dynamique
							if (is_array($col) && count($col) === 3) {
								$pdf->SetTextColor($col[0], $col[1], $col[2]);
							}
						} elseif (is_array($colorsLine1[$i]) && count($colorsLine1[$i]) === 3) {
							$pdf->SetTextColor($colorsLine1[$i][0], $colorsLine1[$i][1], $colorsLine1[$i][2]);
						}
					} else {
						// couleur par d�faut si colonne non index�e
						$pdf->SetTextColor(0,0,0);
					}

                $lineHeight = $sz2 * 0.35;
                $offsetY = ($rowHeight - $lineHeight) / 2;
                $pdf->SetXY($x, $y + $offsetY);
                $pdf->MultiCell($w, $lineHeight, $row[$i], 0, $a);
            }

            $pdf->SetXY($x + $w, $y);
        }
        $pdf->Ln($rowHeight);
    }
}

	
	
	
	
	
	
	
	
function FancyTable_style($pdf, $data, $widths, $aligns = [], $rowHeight = 10, $startX = null, $borderStyle = 'simple', $fonts = [], $colors = [])
{
    if ($startX !== null) {
        $pdf->tableStartX = $startX; // m�moriser pour les sauts de page
    }

    foreach ($data as $row) {
        if ($startX !== null) {
            $pdf->SetX($startX);
        }

        $pdf->My_CheckPageBreak($rowHeight);

        for ($i = 0; $i < count($row); $i++) {
            $w = $widths[$i];
            $a = isset($aligns[$i]) ? $aligns[$i] : 'L';

            $x = $pdf->GetX();
            $y = $pdf->GetY();

            // --- Bordures ---
            switch ($borderStyle) {
                case 'simple':
                    $pdf->SetLineWidth(0.2);
                    $pdf->Rect($x, $y, $w, $rowHeight);
                    break;
                case 'bold':
                    $pdf->SetLineWidth(0.5);
                    $pdf->Rect($x, $y, $w, $rowHeight);
                    $pdf->SetLineWidth(0.2);
                    break;
                case 'double':
                    $pdf->SetLineWidth(0.2);
                    $pdf->Rect($x, $y, $w, $rowHeight);
                    $pdf->Rect($x + 0.4, $y + 0.4, $w - 1, $rowHeight - 1);
                    break;
            }

            // --- Police personnalis�e par cellule ---
            if (isset($fonts[$i]) && is_array($fonts[$i])) {
                list($family, $style, $size) = $fonts[$i];
                $pdf->SetFont($family, $style, $size);
            }

            // --- Couleur personnalis�e par cellule ---
            if (isset($colors[$i])) {
                // $colors[$i] peut �tre une fonction callback ou un tableau [r,g,b]
                if (is_callable($colors[$i])) {
                    // Ex�cution de la r�gle dynamique
                    $col = $colors[$i]($row[$i]);
                    if (is_array($col) && count($col) === 3) {
                        $pdf->SetTextColor($col[0], $col[1], $col[2]);
                    }
                } elseif (is_array($colors[$i]) && count($colors[$i]) === 3) {
                    // Couleur fixe
                    $pdf->SetTextColor($colors[$i][0], $colors[$i][1], $colors[$i][2]);
                }
            } else {
                // couleur par d�faut (noir)
                $pdf->SetTextColor(0, 0, 0);
            }

            // --- Texte centr� verticalement ---
            $nbLines = $pdf->NbLines($w, $row[$i]);
            $lineHeight = $pdf->FontSizePt * 0.35;
            $textHeight = $nbLines * $lineHeight;
            $offsetY = ($rowHeight - $textHeight) / 2;

            $pdf->SetXY($x, $y + $offsetY);
            $pdf->MultiCell($w, $lineHeight, $row[$i], 0, $a);

            // Repositionner pour la prochaine cellule
            $pdf->SetXY($x + $w, $y);

            // ? Reset police et couleur par d�faut
            $pdf->SetFont('Arial', '', 12);
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln($rowHeight);
    }
}
	
	
	
	
	
function FancyTableRow($pdf, $data, $widths, $aligns = [], $rowHeight = 10, $startX = null)
{
    // Si une position X est donn�e, on place le curseur
    if ($startX !== null) {
        $pdf->SetX($startX);
    }

    // V�rifier si on d�passe la page
    $pdf->My_CheckPageBreak($rowHeight);

    // Dessiner chaque cellule
    for ($i = 0; $i < count($data); $i++) {
        $w = $widths[$i];
        $a = isset($aligns[$i]) ? $aligns[$i] : 'L';

        // Sauvegarder position
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        // Dessiner le cadre
        $pdf->Rect($x, $y, $w, $rowHeight);

        // Calcul du nombre de lignes pour ce texte
        $nbLines = $pdf->NbLines($w, $data[$i]);
        $textHeight = $nbLines * 5; // 5 = hauteur par ligne

        // D�calage vertical pour centrer le texte dans la cellule
        $offsetY = ($rowHeight - $textHeight) / 2;

        // Positionner le curseur au bon endroit
        $pdf->SetXY($x, $y + $offsetY);

        // �crire le texte avec l�alignement demand�
        $pdf->MultiCell($w, 5, $data[$i], 0, $a);

        // Repositionner � droite pour la prochaine cellule
        $pdf->SetXY($x + $w, $y);
    }
    // Aller � la ligne suivante
    $pdf->Ln($rowHeight);
}
	
	
	
	
	
	

	
	
	
	
	
function printTableHeaderComplex($jours, $periodes, $wColonnesFixes, $labelsFixes, $hauteur, $fontFamily, $fontStyle, $fontSize, $wPeriode,$setX)
{
    $this->SetFont($fontFamily, $fontStyle, $fontSize);
   // $this->SetFillColor(230, 230, 230); // gris clair

    // 1ère ligne : colonnes fixes fusionnées verticalement
    foreach ($labelsFixes as $i => $label) {
        $this->Cell($wColonnesFixes[$i], $hauteur * 2, strtoupper($label), 1, 0, 'C', true);
    }

    // Colonnes jours fusionnées horizontalement
    foreach ($jours as $jour) {
        $this->Cell($wPeriode * count($periodes), $hauteur, strtoupper($jour), 1, 0, 'C', true);
    }
    $this->Ln();
$this->SetX($setX);
    // 2e ligne : périodes sous chaque jour
    foreach ($labelsFixes as $i => $label) {
        $this->Cell($wColonnesFixes[$i], $hauteur, '', 0, 0); // vide sous colonnes fixes
    }

    foreach ($jours as $jour) {
        foreach ($periodes as $periode) {
            $this->Cell($wPeriode, $hauteur, strtoupper($periode), 1, 0, 'C');
        }
    }
    $this->Ln();
}	
	
	
function printTableHeaderComplexPlus($jours, $periodes, $wColonnesFixes, $labelsFixes, $wColonnesFinales, $labelsFinales, $hauteur, $fontFamily, $fontStyle, $fontSize, $wPeriode, $setX)
{
    $this->SetFont($fontFamily, $fontStyle, $fontSize);
    $this->SetFillColor(230, 230, 230); // gris clair

    // 1ère ligne : colonnes fixes fusionnées verticalement
    foreach ($labelsFixes as $i => $label) {
        $this->Cell($wColonnesFixes[$i], $hauteur * 2, strtoupper($label), 1, 0, 'C', true);
    }

    // Colonnes jours fusionnées horizontalement
    foreach ($jours as $jour) {
        $this->Cell($wPeriode * count($periodes), $hauteur, strtoupper($jour), 1, 0, 'C', true);
    }

    // Colonnes finales fusionnées verticalement
    foreach ($labelsFinales as $i => $label) {
        $this->Cell($wColonnesFinales[$i], $hauteur * 2, strtoupper($label), 1, 0, 'C', true);
    }

    $this->Ln($hauteur);
    $this->SetX($setX);

    // 2e ligne : périodes sous chaque jour
    $totalFixedWidth = array_sum($wColonnesFixes);
    $this->Cell($totalFixedWidth, $hauteur, '', 0, 0); // espace sous colonnes fixes

    foreach ($jours as $jour) {
        foreach ($periodes as $periode) {
            $this->Cell($wPeriode, $hauteur, strtoupper($periode), 1, 0, 'C');
        }
    }

    // Colonnes finales : vide sous fusion verticale
    $totalFinalWidth = array_sum($wColonnesFinales);
    $this->Cell($totalFinalWidth, $hauteur, '', 0, 0); // espace sous colonnes finales

    $this->Ln();
}
	
	
function printTableHeader_haut_SetFont_Array($header,$w,$align,$border,$hauteur,$fontFamily, $fontStyle, $fontSize)
{
	$this->SetMargins(5,60,5);
	// Appliquer la police
    $this->SetFont($fontFamily, $fontStyle, $fontSize);
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],$hauteur,$header[$i],$border,0,$align[$i],1);
}
function printTableHeader_haut_SetFont($header,$w,$align,$border,$hauteur,$SetFont)
{
	$this->SetMargins(5,60,5);
	$SetFont;
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],$hauteur,$header[$i],$border,0,$align[$i],1);
}	
	
	
	
function printTableHeader_with_SetFont_and_fill($header,$w,$align,$border,$hauteur,$SetFont,$fill)
{
	//Couleurs, épaisseur du trait et police grasse
	//
	$this->SetMargins(5,60,5);
	//$this->SetFillColor(142,17,155);
	$SetFont;
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],$hauteur,$header[$i],$border,0,$align[$i],$fill);
}
function printTable_visa($header,$w)
{
	//Couleurs, épaisseur du trait et police grasse
	//
	$this->SetMargins(5,60,5);
	//$this->SetFillColor(142,17,155);
	$this->SetFont('Arial','B',9.5);
	for($i=0; $i<count($header); $i++)
		$this->Cell($w[$i],5,$header[$i],0,0,'C',1);
}	
	
	
	
	
	
	
function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F')
            $op='f';
        elseif($style=='FD' || $style=='DF')
            $op='B';
        else
            $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));

        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
    }
	
		//----------------------------------------------------------------------------
var $angle=0;

function Rotate($angle,$x=-1,$y=-1)
{
    if($x==-1)
        $x=$this->x;
    if($y==-1)
        $y=$this->y;
    if($this->angle!=0)
        $this->_out('Q');
    $this->angle=$angle;
    if($angle!=0)
    {
        $angle*=M_PI/180;
        $c=cos($angle);
        $s=sin($angle);
        $cx=$x*$this->k;
        $cy=($this->h-$y)*$this->k;
        $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',$c,$s,-$s,$c,$cx,$cy,-$cx,-$cy));
    }
}
function RotatedImage($file,$x,$y,$w,$h,$angle)
{
    //Rotation de l'image autour du coin supérieur gauche
    $this->Rotate($angle,$x,$y);
    $this->Image($file,$x,$y,$w,$h);
    $this->Rotate(0);
}
function RotatedText($x, $y, $txt, $angle)
{
    //Rotation du texte autour de son origine
    $this->Rotate($angle,$x,$y);
    $this->Text($x,$y,$txt);
    $this->Rotate(0);
}

/*function multiCell_fix($w, $h, $txt, $border = 0, $align = 'J', $fill = 0, $cursorPos = 1) {
		// NB : ces contorsions sont rendues nécessaires par le fait que dans la classe de base, 
		// SetY() a comme effet de bord de replacer le curseur à gauche...
		if( $cursorPos == 0 ) {
			$y = $this->GetY();
			$x = $this->GetX() + $w;
		}
		parent::MultiCell($w, $h, $txt, $border, $align, $fill);
		if( $cursorPos == 0 ) {
			$this->SetY($y);
			$this->SetX($x);
		}
	}
*/
function Multi_retour($w,$h,$txt,$b,$a,$f)
	{
		$x_m=$this->GetX();
		$y_m=$this->GetY();
		$this->MultiCell($w,$h,$txt,$b,$a,$f);
		$x_m=$x_m+$w;
		$this->SetXY($x_m,$y_m);
	}
	//--------------------------------------------------------------------------
		
	function TextWithDirection($x, $y, $txt, $direction='R')
	{
		
		if ($direction=='R')
			$s=sprintf('BT %.2F %.2F %.2F %.2F %.2F %.2F Tm (%s) Tj ET',1,0,0,1,$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
		elseif ($direction=='L')
			$s=sprintf('BT %.2F %.2F %.2F %.2F %.2F %.2F Tm (%s) Tj ET',-1,0,0,-1,$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
		elseif ($direction=='U')
			$s=sprintf('BT %.2F %.2F %.2F %.2F %.2F %.2F Tm (%s) Tj ET',0,1,-1,0,$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
		elseif ($direction=='D')
			$s=sprintf('BT %.2F %.2F %.2F %.2F %.2F %.2F Tm (%s) Tj ET',0,-1,1,0,$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
		else
			$s=sprintf('BT %.2F %.2F Td (%s) Tj ET',$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
		if ($this->ColorFlag)
			$s='q '.$this->TextColor.' '.$s.' Q';
		$this->_out($s);
	}
	
	function TextWithRotation($x, $y, $txt, $txt_angle, $font_angle=0)
	{
		$font_angle+=90+$txt_angle;
		$txt_angle*=M_PI/180;
		$font_angle*=M_PI/180;
	
		$txt_dx=cos($txt_angle);
		$txt_dy=sin($txt_angle);
		$font_dx=cos($font_angle);
		$font_dy=sin($font_angle);
	
		$s=sprintf('BT %.2F %.2F %.2F %.2F %.2F %.2F Tm (%s) Tj ET',$txt_dx,$txt_dy,$font_dx,$font_dy,$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
		if ($this->ColorFlag)
			$s='q '.$this->TextColor.' '.$s.' Q';
		$this->_out($s);
	}
	
	
	
	
	
	/*
This method prints text from the current position in the same way as Write(). An additional parameter allows to reduce or increase the font size; it's useful for initials. A second parameter allows to specify an offset so that text is placed at a superscripted or subscripted position.

subWrite(float h, string txt [, mixed link [, float subFontSize [, float subOffset]]])

h: line height
txt: string to print
link: URL or identifier returned by AddLink()
subFontSize: size of font in points (12 by default)
subOffset: offset of text in points (positive means superscript, negative subscript; 0 by default)

*/


function subWrite($h, $txt, $link='', $subFontSize=12, $subOffset=0)
{
    // resize font
    $subFontSizeold = $this->FontSizePt;
    $this->SetFontSize($subFontSize);
    
    // reposition y
    $subOffset = ((($subFontSize - $subFontSizeold) / $this->k) * 0.3) + ($subOffset / $this->k);
    $subX        = $this->x;
    $subY        = $this->y;
    $this->SetXY($subX, $subY - $subOffset);

    //Output text
    $this->Write($h, $txt, $link);

    // restore y position
    $subX        = $this->x;
    $subY        = $this->y;
    $this->SetXY($subX,  $subY + $subOffset);

    // restore font size
    $this->SetFontSize($subFontSizeold);
}

function DiagonalTextBlock($x, $y, $w, $h, $text, $angle=45, $space=30)
{
    // Police
    $this->SetFont('Arial','',5);
    $this->SetTextColor(180,180,180); // gris clair

    // Rotation autour du coin
    $this->Rotate($angle, $x, $y);

    // Remplissage
    for ($i = -$h; $i < $w; $i += $space)
    {
        for ($j = 0; $j < $h*1.5; $j += 5)
        {
            $this->SetXY($x + $i, $y + $j);
            $this->Cell(30,4,$text,0);
        }
    }

    // Reset rotation
    $this->Rotate(0);
}
	
	///gestion de la transparence (alpha channel), ce qui permet de conserver les zones transparentes de tes PNG dans le PDF.
	
public function SetAlpha($alpha, $bm='Normal')
{
    if($alpha<0) $alpha=0;
    if($alpha>1) $alpha=1;
    $gs = $this->AddExtGState(['ca'=>$alpha, 'CA'=>$alpha, 'BM'=>'/'.$bm]);
    $this->SetExtGState($gs);
}

protected function AddExtGState($parms)
{
    $n = count($this->extgstates)+1;
    $this->extgstates[$n] = $parms;
    return $n;
}

protected function SetExtGState($gs)
{
    $this->_out(sprintf('/GS%d gs', $gs));
}
    

    

    
	protected function _putextgstate($parms)
{
    $this->_newobj();
    $this->extgstates[count($this->extgstates)]['n'] = $this->n;
    $this->_out('<</Type /ExtGState');
    $this->_out('/ca '.$parms['ca']);
    $this->_out('/CA '.$parms['CA']);
    $this->_out('/BM '.$parms['BM']);
    $this->_out('>>');
    $this->_out('endobj');
}
	
	
	
	
	
	
}
?>
