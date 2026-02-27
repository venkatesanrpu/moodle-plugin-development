<?php
/**
 * FILE: local/ai_functions/lib_dummy.php
 * PURPOSE: Dummy AI function responses for local testing without API calls
 * UPDATE: Added mcq_widget dummy response with 5 sample MCQs
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Dummy function to simulate AI endpoint calls
 * Returns dummy data based on function name
 *
 * @param string $agentconfigkey The agent key
 * @param string $functionname The function being called
 * @param array|stdClass $payload The request payload
 * @return string JSON response
 */
function local_ai_functions_call_endpoint($agentconfigkey, $functionname, $payload) {
    
    // Simulate network latency
    sleep(1);
    
    // Handle different function types
    switch ($functionname) {
        
        case 'mcq_widget':
            return generate_dummy_mcqs($payload);
            
        case 'ask_agent':
            return json_encode([
                'response' => "This is a dummy RAG response. The 'ask_agent' function was called correctly.\n\nLaTeX test: \\(\\frac{1}{2}\\) and \\[E = mc^2\\]"
            ]);
            
        case 'mcq':
            return json_encode([
                'response' => "Here are 5 dummy MCQs. The 'mcq' function was called correctly.\n\n**Question 1**: What is the capital of France?\nA) London\nB) Paris\nC) Berlin\nD) Madrid\n\n**Correct Answer**: B"
            ]);
            
        case 'youtube_summarize':
            return json_encode([
                'response' => "This is a dummy YouTube summary. The video discusses advanced topics in chemistry with mathematical formulas like \\(\\Delta G = \\Delta H - T\\Delta S\\)."
            ]);
            
        case 'websearch':
            return json_encode([
                'response' => "This is a dummy web search result showing information about Boron Hydrides. The Grotthuss mechanism is expressed as \\(\\Gamma\\)."
            ]);
            
        default:
            return json_encode([
                'response' => "Unknown function: {$functionname}"
            ]);
    }
}

/**
 * Generate dummy MCQ data for flashcard testing
 *
 * @param array $payload Request parameters
 * @return string JSON response with MCQ data
 */
function generate_dummy_mcqs($payload) {
    $level = isset($payload['level']) ? $payload['level'] : 'basic';
    $subject = isset($payload['subject']) ? $payload['subject'] : 'Chemistry';
    $topic = isset($payload['topic']) ? $payload['topic'] : 'General';
    
    // Generate 5 dummy MCQs
    $mcq_data = [
        'mcq_data' => [
            'questions' => [
                [
                    'question' => 'What is the molecular geometry of methane (CH₄)?',
                    'options' => [
                        'Linear',
                        'Tetrahedral',
                        'Trigonal planar',
                        'Octahedral'
                    ],
                    'correct' => 'B',
                    'explanation' => 'Methane has a tetrahedral geometry because the carbon atom forms four equivalent sp³ hybrid orbitals, each bonding with a hydrogen atom. The bond angle is approximately 109.5°, characteristic of tetrahedral geometry.'
                ],
                [
                    'question' => 'Which of the following is an example of a three-center two-electron (3c-2e) bond?',
                    'options' => [
                        'C-C bond in ethane',
                        'B-H-B bond in diborane',
                        'O-H bond in water',
                        'N-N triple bond in nitrogen gas'
                    ],
                    'correct' => 'B',
                    'explanation' => 'Diborane (B₂H₆) contains bridging hydrogen atoms that form 3c-2e bonds. Each bridging hydrogen shares its electron pair with two boron atoms simultaneously, making it electron-deficient yet stable through this unique bonding arrangement.'
                ],
                [
                    'question' => 'What is the oxidation state of chromium in potassium dichromate (K₂Cr₂O₇)?',
                    'options' => [
                        '+3',
                        '+4',
                        '+6',
                        '+7'
                    ],
                    'correct' => 'C',
                    'explanation' => 'In K₂Cr₂O₇, each potassium has +1 oxidation state and each oxygen has -2. Using the formula: 2(+1) + 2(x) + 7(-2) = 0, we get x = +6. Chromium exhibits its maximum oxidation state in this compound, making it a strong oxidizing agent.'
                ],
                [
                    'question' => 'Which quantum number determines the shape of an atomic orbital?',
                    'options' => [
                        'Principal quantum number (n)',
                        'Azimuthal quantum number (l)',
                        'Magnetic quantum number (mₗ)',
                        'Spin quantum number (mₛ)'
                    ],
                    'correct' => 'B',
                    'explanation' => 'The azimuthal quantum number (l) determines the shape of the orbital. For example, l=0 corresponds to spherical s-orbitals, l=1 to dumbbell-shaped p-orbitals, and l=2 to cloverleaf-shaped d-orbitals. The principal quantum number (n) determines the size and energy level.'
                ],
                [
                    'question' => 'What is the coordination number of the central metal ion in [Fe(CN)₆]³⁻?',
                    'options' => [
                        '3',
                        '4',
                        '6',
                        '8'
                    ],
                    'correct' => 'C',
                    'explanation' => 'The coordination number is 6 because six cyanide (CN⁻) ligands are directly bonded to the central iron(III) ion. This octahedral geometry is common for d-block transition metals and results in strong ligand field splitting, making this complex low-spin and diamagnetic.'
                ]
            ]
        ]
    ];
    
    return json_encode($mcq_data);
}

/**
 * Dummy function for non-streaming JSON responses (future use)
 */
function local_ai_functions_call_endpoint_json($agentconfigkey, $functionname, $payload) {
    // For now, redirect to the main dummy function
    $response_string = local_ai_functions_call_endpoint($agentconfigkey, $functionname, $payload);
    return json_decode($response_string, true);
}
